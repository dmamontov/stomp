<?php

namespace React\Tests\Stomp\Protocol;

use PHPUnit\Framework\TestCase;
use React\Stomp\Protocol\Frame;

/**
 * @internal
 * @coversNothing
 */
class FrameDumpTest extends TestCase
{
    /**
     * @test
     * @dataProvider provideFramesAndTheirWireData
     *
     * @param mixed $expected
     */
    public function toStringShouldDumpMessageToWireProtocol($expected, Frame $frame)
    {
        $this->assertSame($expected, (string) $frame);
    }

    public function provideFramesAndTheirWireData()
    {
        return [
            [
                "CONNECT\naccept-version:1.1\nhost:stomp.github.org\n\n\x00",
                new Frame('CONNECT', ['accept-version' => '1.1', 'host' => 'stomp.github.org']),
            ],
            [
                "MESSAGE\nheader1:value1\nheader2:value2\n\nBody\x00",
                new Frame('MESSAGE', ['header1' => 'value1', 'header2' => 'value2'], 'Body'),
            ],
            [
                "MESSAGE\nfoo:bar\\nbaz\nbaz:baz\\cin\\\\ga\n\n\x00",
                new Frame('MESSAGE', ['foo' => "bar\nbaz", 'baz' => 'baz:in\\ga']),
            ],
        ];
    }
}
