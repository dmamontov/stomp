<?php

namespace React\Stomp\Io;

use Evenement\EventEmitter;

class WritableStream extends EventEmitter implements WritableStreamInterface
{
    private $stream;
    private $loop;
    private $softLimit;
    private $writeChunkSize;

    private $listening = false;
    private $writable = true;
    private $closed = false;
    private $data = '';

    public function isWritable()
    {
        return $this->writable;
    }

    public function end($data = null)
    {
        if (null !== $data) {
            $this->write($data);
        }

        $this->writable = false;

        // close immediately if buffer is already empty
        // otherwise wait for buffer to flush first
        if ('' === $this->data) {
            $this->close();
        }
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        if ($this->listening) {
            $this->listening = false;
            $this->loop->removeWriteStream($this->stream);
        }

        $this->closed = true;
        $this->writable = false;
        $this->data = '';

        $this->emit('close');
        $this->removeAllListeners();

        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
    }

    /** @internal */
    public function handleWrite()
    {
        $error = null;
        \set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$error) {
            $error = [
                'message' => $errstr,
                'number' => $errno,
                'file' => $errfile,
                'line' => $errline,
            ];
        });

        if (-1 === $this->writeChunkSize) {
            $sent = \fwrite($this->stream, $this->data);
        } else {
            $sent = \fwrite($this->stream, $this->data, $this->writeChunkSize);
        }

        \restore_error_handler();

        // Only report errors if *nothing* could be sent.
        // Any hard (permanent) error will fail to send any data at all.
        // Sending excessive amounts of data will only flush *some* data and then
        // report a temporary error (EAGAIN) which we do not raise here in order
        // to keep the stream open for further tries to write.
        // Should this turn out to be a permanent error later, it will eventually
        // send *nothing* and we can detect this.
        if (0 === $sent || false === $sent) {
            if (null !== $error) {
                $error = new \ErrorException(
                    $error['message'],
                    0,
                    $error['number'],
                    $error['file'],
                    $error['line']
                );
            }

            $this->emit('error', [new \RuntimeException('Unable to write to stream: '.(null !== $error ? $error->getMessage() : 'Unknown error'), 0, $error)]);
            $this->close();

            return;
        }

        $exceeded = isset($this->data[$this->softLimit - 1]);
        $this->data = (string) \substr($this->data, $sent);

        // buffer has been above limit and is now below limit
        if ($exceeded && !isset($this->data[$this->softLimit - 1])) {
            $this->emit('drain');
        }

        // buffer is now completely empty => stop trying to write
        if ('' === $this->data) {
            // stop waiting for resource to be writable
            if ($this->listening) {
                $this->loop->removeWriteStream($this->stream);
                $this->listening = false;
            }

            // buffer is end()ing and now completely empty => close buffer
            if (!$this->writable) {
                $this->close();
            }
        }
    }
}
