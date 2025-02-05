<?php

namespace React\Tests\Stomp\Protocol;

use PHPUnit\Framework\TestCase;
use React\Stomp\Protocol\InvalidFrameException;
use React\Stomp\Protocol\Parser;

/**
 * @internal
 * @coversNothing
 */
class ParserTest extends TestCase
{
    /** @test */
    public function itShouldParseASingleFrame()
    {
        $data = "MESSAGE
header1:value1
header2:value2

Body\x00";

        $parser = new Parser();
        list($frames, $data) = $parser->parse($data);

        $this->assertHasSingleFrame(
            'MESSAGE',
            ['header1' => 'value1', 'header2' => 'value2'],
            'Body',
            $data,
            $frames
        );
    }

    /**
     * ActiveMQ and Apollo add an extra new line character between the end of
     * a frame and the next command.
     *
     * @test
     */
    public function itShouldParseASingleFrameStartingWithANewLine()
    {
        $data = "
MESSAGE
header1:value1
header2:value2

Body\x00";

        $parser = new Parser();
        list($frames, $data) = $parser->parse($data);

        $this->assertHasSingleFrame(
            'MESSAGE',
            ['header1' => 'value1', 'header2' => 'value2'],
            'Body',
            $data,
            $frames
        );
    }

    /** @test */
    public function itShouldAllowUtf8InHeaders()
    {
        $data = "MESSAGE
äöü:~

\x00";

        $parser = new Parser();
        list($frames, $data) = $parser->parse($data);

        $this->assertHasSingleFrame(
            'MESSAGE',
            ['äöü' => '~'],
            '',
            $data,
            $frames
        );
    }

    /** @test */
    public function itShouldUnescapeSpecialCharactersInHeaders()
    {
        $data = "MESSAGE
foo:bar\\nbaz
bazin\\nga:bar\\c\\\\

\x00";

        $parser = new Parser();
        list($frames, $data) = $parser->parse($data);

        $this->assertHasSingleFrame(
            'MESSAGE',
            ['foo' => "bar\nbaz", "bazin\nga" => 'bar:\\'],
            '',
            $data,
            $frames
        );
    }

    /**
     * @test
     * @expectedException \React\Stomp\Protocol\InvalidFrameException
     * @expectedExceptionMessage Provided header 'foo:bar\r' contains undefined escape sequences.
     */
    public function itShouldRejectUndefinedEscapeSequences()
    {
        $data = "MESSAGE
foo:bar\\r

\x00";

        $parser = new Parser();
        $parser->parse($data);
    }

    /**
     * @test
     * @dataProvider provideFramesThatCanHaveABody
     *
     * @param mixed $body
     * @param mixed $data
     */
    public function itShouldAllowCertainFramesToHaveABody($body, $data)
    {
        $parser = new Parser();
        list($frames, $data) = $parser->parse($data);

        $this->assertSame($body, $frames[0]->body);
    }

    public function provideFramesThatCanHaveABody()
    {
        return [
            ['Body', "SEND\nfoo:bar\n\nBody\x00"],
            ['Body', "MESSAGE\nfoo:bar\n\nBody\x00"],
            ['Body', "ERROR\nfoo:bar\n\nBody\x00"],
        ];
    }

    /**
     * @test
     * @dataProvider provideFrameCommandsThatMustNotHaveABody
     *
     * @param mixed $command
     */
    public function itShouldRejectOtherFramesWithBody($command)
    {
        $data = "{$command}\nfoo:bar\n\nBody\x00";

        $parser = new Parser();

        try {
            $parser->parse($data);
            $this->fail('Expected exception of type React\Stomp\Protocol\InvalidFrameException.');
        } catch (InvalidFrameException $e) {
            $expected = sprintf("Frames of command '%s' must not have a body.", $command);
            $this->assertSame($expected, $e->getMessage());
        }
    }

    /**
     * @test
     * @dataProvider provideFrameCommandsThatMustNotHaveABody
     *
     * @param mixed $command
     */
    public function itShouldAcceptOtherFramesWithoutBody($command)
    {
        $data = "{$command}\nfoo:bar\n\n\x00";

        $parser = new Parser();
        $parser->parse($data);
    }

    public function provideFrameCommandsThatMustNotHaveABody()
    {
        return [
            ['CONNECT'],
            ['CONNECTED'],
            ['BEGIN'],
            ['DISCONNECT'],
            ['FOOBAR'],
        ];
    }

    /** @test */
    public function itShouldNotTrimHeaders()
    {
        $parser = new Parser();
        $data = "MESSAGE\nfoo   :   bar baz   \n\n\x00";

        $parser = new Parser();
        list($frames, $data) = $parser->parse($data);

        $this->assertHasSingleFrame(
            'MESSAGE',
            ['foo   ' => '   bar baz   '],
            '',
            $data,
            $frames
        );
    }

    /** @test */
    public function itShouldPickTheFirstHeaderValueOfRepeatedHeaderNames()
    {
        $parser = new Parser();
        $data = "MESSAGE
foo:bar
foo:baz

\x00";

        $parser = new Parser();
        list($frames, $data) = $parser->parse($data);

        $this->assertHasSingleFrame(
            'MESSAGE',
            ['foo' => 'bar'],
            '',
            $data,
            $frames
        );
    }

    public function assertHasSingleFrame($command, $headers, $body, $data, $frames)
    {
        $this->assertCount(1, $frames);
        $this->assertInstanceOf('React\Stomp\Protocol\Frame', $frames[0]);
        $this->assertSame($command, $frames[0]->command);
        $this->assertSame($headers, $frames[0]->headers);
        $this->assertSame($body, $frames[0]->body);

        $this->assertSame('', $data);
    }
}
