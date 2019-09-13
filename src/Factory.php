<?php

namespace React\Stomp;

use React\EventLoop\LoopInterface;
use React\Socket\Connection;
use React\Stomp\Exception\ConnectionException;
use React\Stomp\Io\InputStream;
use React\Stomp\Io\OutputStream;
use React\Stomp\Protocol\Parser;

class Factory
{
    private $defaultOptions = [
        'host' => '127.0.0.1',
        'port' => 61613,
        'vhost' => '/',
        'login' => 'guest',
        'passcode' => 'guest',
    ];

    private $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function createClient(array $options = [])
    {
        $options = array_merge($this->defaultOptions, $options);

        $conn = $this->createConnection($options);

        $parser = new Parser();
        $input = new InputStream($parser);
        $conn->pipe($input);

        $output = new OutputStream($this->loop);
        $output->pipe($conn);

        $conn->on('error', function ($e) use ($input) {
            $input->emit('error', [$e]);
        });
        $conn->on('close', function () use ($input) {
            $input->emit('close');
        });

        return new Client($this->loop, $input, $output, $options);
    }

    public function createConnection($options)
    {
        $address = 'tcp://'.$options['host'].':'.$options['port'];

        if (false === $fd = @stream_socket_client($address, $errno, $errstr)) {
            $message = "Could not bind to {$address}: {$errstr}";

            throw new ConnectionException($message, $errno);
        }

        return new Connection($fd, $this->loop);
    }
}
