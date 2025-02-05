<?php

namespace React\Stomp\Protocol;

class Frame
{
    public $command;
    public $headers = [];
    public $body;

    public function __construct($command = null, array $headers = [], $body = '')
    {
        $this->command = $command;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function __toString()
    {
        return $this->dump();
    }

    public function getHeader($name)
    {
        return isset($this->headers[$name]) ? $this->headers[$name] : null;
    }

    public function dump()
    {
        return  $this->command."\n".
                $this->dumpHeaders()."\n".
                $this->body.
                "\x00";
    }

    private function dumpHeaders()
    {
        $dumped = '';

        foreach ($this->headers as $name => $value) {
            $name = $this->escapeHeaderValue($name);
            $value = $this->escapeHeaderValue($value);

            $dumped .= "{$name}:{$value}\n";
        }

        return $dumped;
    }

    private function escapeHeaderValue($value)
    {
        return strtr($value, [
            "\n" => '\n',
            ':' => '\c',
            '\\' => '\\\\',
        ]);
    }
}
