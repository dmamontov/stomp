<?php

namespace React\Stomp;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stomp\Client\Command\CloseCommand;
use React\Stomp\Client\Command\CommandInterface;
use React\Stomp\Client\Command\ConnectionEstablishedCommand;
use React\Stomp\Client\Command\NullCommand;
use React\Stomp\Client\IncomingPackageProcessor;
use React\Stomp\Client\OutgoingPackageCreator;
use React\Stomp\Client\State;
use React\Stomp\Exception\ConnectionException;
use React\Stomp\Exception\ProcessingException;
use React\Stomp\Io\InputStreamInterface;
use React\Stomp\Io\OutputStreamInterface;
use React\Stomp\Protocol\Frame;

/**
 * @event connect
 * @event error
 */
class Client extends EventEmitter
{
    private $loop;
    private $connectionStatus = 'not-connected';
    private $packageProcessor;
    private $packageCreator;
    private $subscriptions = [];
    private $acknowledgements = [];
    private $options = [];

    /** @var Deferred */
    private $connectDeferred;

    /** @var PromiseInterface */
    private $connectPromise;

    public function __construct(LoopInterface $loop, InputStreamInterface $input, OutputStreamInterface $output, array $options)
    {
        $this->loop = $loop;
        $state = new State();
        $this->packageProcessor = new IncomingPackageProcessor($state);
        $this->packageCreator = new OutgoingPackageCreator($state);

        $this->input = $input;
        $this->input->on('frame', [$this, 'handleFrameEvent']);
        $this->input->on('error', [$this, 'handleErrorEvent']);
        $this->input->on('close', [$this, 'handleCloseEvent']);
        $this->output = $output;

        $this->options = $this->sanatizeOptions($options);
    }

    public function connect($timeout = 5)
    {
        if ($this->connectPromise) {
            return $this->connectPromise;
        }

        $this->connectionStatus = 'connecting';

        $deferred = $this->connectDeferred = new Deferred();
        $client = $this;

        $timer = $this->loop->addTimer($timeout, function () use ($client, $deferred) {
            $deferred->reject(new ConnectionException('Connection timeout'));
            $client->resetConnectDeferred();
            $client->setConnectionStatus('not-connected');
        });

        $this->on('connect', function ($client) use ($timer, $deferred) {
            $this->loop->cancelTimer($timer);
            $deferred->resolve($client);
        });

        $frame = $this->packageCreator->connect(
            $this->options['vhost'],
            $this->options['login'],
            $this->options['passcode']
        );
        $this->output->sendFrame($frame);

        return $this->connectPromise = $deferred->promise()->then(function () use ($client) {
            $client->setConnectionStatus('connected');

            return $client;
        });
    }

    public function send($destination, $body, array $headers = [])
    {
        $frame = $this->packageCreator->send($destination, $body, $headers);
        $this->output->sendFrame($frame);
    }

    public function subscribe($destination, $callback, array $headers = [])
    {
        return $this->doSubscription($destination, $callback, 'auto', $headers);
    }

    public function subscribeWithAck($destination, $ack, $callback, array $headers = [])
    {
        if ('auto' === $ack) {
            throw new \LogicException("ack 'auto' is not compatible with acknowledgeable subscription");
        }

        return $this->doSubscription($destination, $callback, $ack, $headers);
    }

    public function unsubscribe($subscriptionId, array $headers = [])
    {
        $frame = $this->packageCreator->unsubscribe($subscriptionId, $headers);
        $this->output->sendFrame($frame);

        unset($this->acknowledgements[$subscriptionId], $this->subscriptions[$subscriptionId]);
    }

    public function ack($subscriptionId, $messageId, array $headers = [])
    {
        $frame = $this->packageCreator->ack($subscriptionId, $messageId, $headers);
        $this->output->sendFrame($frame);
    }

    public function nack($subscriptionId, $messageId, array $headers = [])
    {
        $frame = $this->packageCreator->nack($subscriptionId, $messageId, $headers);
        $this->output->sendFrame($frame);
    }

    public function disconnect()
    {
        $receipt = $this->generateReceiptId();
        $frame = $this->packageCreator->disconnect($receipt);
        $this->output->sendFrame($frame);

        $this->connectDeferred = null;
        $this->connectPromise = null;
        $this->connectionStatus = 'not-connected';
    }

    public function resetConnectDeferred()
    {
        $this->connectDeferred = null;
        $this->connectPromise = null;
    }

    public function handleFrameEvent(Frame $frame)
    {
        try {
            $this->processFrame($frame);
        } catch (ProcessingException $e) {
            $this->emit('error', [$e]);

            if ('connecting' === $this->connectionStatus) {
                $this->connectDeferred->reject($e);
                $this->connectDeferred = null;
                $this->connectPromise = null;
                $this->connectionStatus = 'not-connected';
            }
        }
    }

    public function handleErrorEvent(\Exception $e)
    {
        $this->emit('error', [$e]);
    }

    public function handleCloseEvent()
    {
        $this->connectDeferred = null;
        $this->connectPromise = null;
        $this->connectionStatus = 'not-connected';

        $this->emit('close');
    }

    public function processFrame(Frame $frame)
    {
        $command = $this->packageProcessor->receiveFrame($frame);
        $this->executeCommand($command);

        if ('MESSAGE' === $frame->command) {
            $this->notifySubscribers($frame);

            return;
        }
    }

    public function executeCommand(CommandInterface $command)
    {
        if ($command instanceof CloseCommand) {
            $this->output->close();

            return;
        }

        if ($command instanceof ConnectionEstablishedCommand) {
            $this->emit('connect', [$this]);

            return;
        }

        if ($command instanceof NullCommand) {
            return;
        }

        throw new \Exception(sprintf("Unknown command '%s'", get_class($command)));
    }

    public function notifySubscribers(Frame $frame)
    {
        $subscriptionId = $frame->getHeader('subscription');

        if (!isset($this->subscriptions[$subscriptionId])) {
            return;
        }

        $callback = $this->subscriptions[$subscriptionId];

        if ('auto' !== $this->acknowledgements[$subscriptionId]) {
            $resolver = new AckResolver($this, $subscriptionId, $frame->getHeader('message-id'));
            $parameters = [$frame, $resolver];
        } else {
            $parameters = [$frame];
        }

        call_user_func_array($callback, $parameters);
    }

    public function isConnected()
    {
        return 'connected' === $this->connectionStatus;
    }

    public function setConnectionStatus($status)
    {
        $this->connectionStatus = $status;
    }

    public function generateReceiptId()
    {
        return mt_rand();
    }

    private function doSubscription($destination, $callback, $ack, array $headers)
    {
        $frame = $this->packageCreator->subscribe($destination, $ack, $headers);
        $this->output->sendFrame($frame);

        $subscriptionId = $frame->getHeader('id');

        $this->acknowledgements[$subscriptionId] = $ack;
        $this->subscriptions[$subscriptionId] = $callback;

        return $subscriptionId;
    }

    private function sanatizeOptions($options)
    {
        if (!isset($options['host']) && !isset($options['vhost'])) {
            throw new \InvalidArgumentException('Either host or vhost options must be provided.');
        }

        return array_merge([
            'vhost' => isset($options['host']) ? $options['host'] : null,
            'login' => null,
            'passcode' => null,
        ], $options);
    }
}
