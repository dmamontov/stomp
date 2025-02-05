<?php

namespace React\Tests\Stomp\Constraint;

use PHPUnit_Framework_Constraint as Constraint;
use React\Stomp\Protocol\Frame;

class FrameHasHeader extends Constraint
{
    protected $name;
    protected $value;

    public function __construct($name, $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public function toString()
    {
        return sprintf(
            'has header %s with value %s',
            $this->name,
            json_encode($this->value)
        );
    }

    protected function matches($other)
    {
        if (!$this->isFrame($other)) {
            return false;
        }

        return (string) $this->value === (string) $other->getHeader($this->name);
    }

    protected function failureDescription($other)
    {
        if (!$this->isFrame($other)) {
            return sprintf(
                '%s is a STOMP frame',
                json_encode($other)
            );
        }

        return sprintf(
            '%s has header %s with value %s',
            json_encode($other),
            $this->name,
            json_encode($this->value)
        );
    }

    private function isFrame($value)
    {
        return $value instanceof Frame;
    }
}
