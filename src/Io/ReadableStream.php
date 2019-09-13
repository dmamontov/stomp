<?php

namespace React\Stomp\Io;

use Evenement\EventEmitter;

abstract class ReadableStream extends EventEmitter implements ReadableStreamInterface
{
    /**
     * @var resource
     */
    private $stream;

    private $loop;

    /**
     * Controls the maximum buffer size in bytes to read at once from the stream.
     *
     * This value SHOULD NOT be changed unless you know what you're doing.
     *
     * This can be a positive number which means that up to X bytes will be read
     * at once from the underlying stream resource. Note that the actual number
     * of bytes read may be lower if the stream resource has less than X bytes
     * currently available.
     *
     * This can be `-1` which means read everything available from the
     * underlying stream resource.
     * This should read until the stream resource is not readable anymore
     * (i.e. underlying buffer drained), note that this does not neccessarily
     * mean it reached EOF.
     *
     * @var int
     */
    private $bufferSize;

    private $closed = false;

    public function isReadable()
    {
        return !$this->closed;
    }

    public function pipe(WritableStreamInterface $dest, array $options = [])
    {
        return Util::pipe($this, $dest, $options);
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $this->emit('close');
        $this->pause();
        $this->removeAllListeners();

        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
    }

    /** @internal */
    public function handleData()
    {
        $error = null;
        \set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$error) {
            $error = new \ErrorException(
                $errstr,
                0,
                $errno,
                $errfile,
                $errline
            );
        });

        $data = \stream_get_contents($this->stream, $this->bufferSize);

        \restore_error_handler();

        if (null !== $error) {
            $this->emit('error', [new \RuntimeException('Unable to read from stream: '.$error->getMessage(), 0, $error)]);
            $this->close();

            return;
        }

        if ('' !== $data) {
            $this->emit('data', [$data]);
        } elseif (\feof($this->stream)) {
            // no data read => we reached the end and close the stream
            $this->emit('end');
            $this->close();
        }
    }

    /**
     * Returns whether this is a pipe resource in a legacy environment.
     *
     * This works around a legacy PHP bug (#61019) that was fixed in PHP 5.4.28+
     * and PHP 5.5.12+ and newer.
     *
     * @param resource $resource
     *
     * @return bool
     *
     * @see https://github.com/reactphp/child-process/issues/40
     *
     * @codeCoverageIgnore
     */
    private function isLegacyPipe($resource)
    {
        if (\PHP_VERSION_ID < 50428 || (\PHP_VERSION_ID >= 50500 && \PHP_VERSION_ID < 50512)) {
            $meta = \stream_get_meta_data($resource);

            if (isset($meta['stream_type']) && 'STDIO' === $meta['stream_type']) {
                return true;
            }
        }

        return false;
    }
}
