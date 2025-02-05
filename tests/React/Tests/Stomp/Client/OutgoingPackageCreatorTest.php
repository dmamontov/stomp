<?php

namespace React\Tests\Stomp\Client;

use PHPUnit\Framework\TestCase;
use React\Stomp\Client\OutgoingPackageCreator;
use React\Stomp\Client\State;
use React\Stomp\Protocol\Frame;

/**
 * @internal
 * @coversNothing
 */
class OutgoingPackageCreatorTest extends TestCase
{
    /** @test */
    public function connectShouldEmitConnectFrame()
    {
        $expectedFrame = new Frame('CONNECT', ['accept-version' => '1.1', 'host' => 'stomp.github.org']);

        $state = new State();
        $packageCreator = new OutgoingPackageCreator($state);

        $this->assertSame(State::STATUS_INIT, $state->status);

        $frame = $packageCreator->connect('stomp.github.org');

        $this->assertFrameEquals($expectedFrame, $frame);
        $this->assertSame(State::STATUS_CONNECTING, $state->status);
    }

    /** @test */
    public function connectShouldSetLoginAndPasscodeHeadersIfGiven()
    {
        $expectedFrame = new Frame('CONNECT', ['accept-version' => '1.1', 'host' => 'stomp.github.org', 'login' => 'foo', 'passcode' => 'bar']);

        $state = new State();
        $packageCreator = new OutgoingPackageCreator($state);

        $frame = $packageCreator->connect('stomp.github.org', 'foo', 'bar');

        $this->assertFrameEquals($expectedFrame, $frame);
    }

    /** @test */
    public function sendShouldEmitSendFrame()
    {
        $expectedFrame = new Frame('SEND', ['destination' => '/queue/a', 'content-length' => '13', 'content-type' => 'text/plain'], 'hello queue a');

        $state = new State();
        $packageCreator = new OutgoingPackageCreator($state);

        $frame = $packageCreator->send('/queue/a', 'hello queue a');

        $this->assertFrameEquals($expectedFrame, $frame);
    }

    /** @test */
    public function subscribeShouldEmitSubscribeFrame()
    {
        $expectedFrame = new Frame('SUBSCRIBE', ['id' => 0, 'destination' => '/queue/a', 'ack' => 'auto']);

        $state = new State();
        $packageCreator = new OutgoingPackageCreator($state);

        $frame = $packageCreator->subscribe('/queue/a');

        $this->assertFrameEquals($expectedFrame, $frame);
    }

    /** @test */
    public function subscribeShouldIncrementId()
    {
        $state = new State();
        $packageCreator = new OutgoingPackageCreator($state);

        $expectedFrame = new Frame('SUBSCRIBE', ['id' => 0, 'destination' => '/queue/a', 'ack' => 'auto']);
        $frame = $packageCreator->subscribe('/queue/a');
        $this->assertFrameEquals($expectedFrame, $frame);

        $expectedFrame = new Frame('SUBSCRIBE', ['id' => 1, 'destination' => '/queue/a', 'ack' => 'auto']);
        $frame = $packageCreator->subscribe('/queue/a');
        $this->assertFrameEquals($expectedFrame, $frame);
    }

    /** @test */
    public function unsubscribeShouldEmitUnsubscribeFrame()
    {
        $expectedFrame = new Frame('UNSUBSCRIBE', ['id' => 0]);

        $state = new State();
        $packageCreator = new OutgoingPackageCreator($state);

        $frame = $packageCreator->unsubscribe(0);

        $this->assertFrameEquals($expectedFrame, $frame);
    }

    /** @test */
    public function ackShouldEmitAckFrame()
    {
        $expectedFrame = new Frame('ACK', ['subscription' => 0, 'message-id' => 5]);

        $state = new State();
        $packageCreator = new OutgoingPackageCreator($state);

        $frame = $packageCreator->ack(0, 5);

        $this->assertFrameEquals($expectedFrame, $frame);
    }

    /** @test */
    public function nackShouldEmitNackFrame()
    {
        $expectedFrame = new Frame('NACK', ['subscription' => 0, 'message-id' => 5]);

        $state = new State();
        $packageCreator = new OutgoingPackageCreator($state);

        $frame = $packageCreator->nack(0, 5);

        $this->assertFrameEquals($expectedFrame, $frame);
    }

    /** @test */
    public function beginShouldEmitBeginFrame()
    {
        $expectedFrame = new Frame('BEGIN', ['transaction' => 'tx1']);

        $state = new State();
        $packageCreator = new OutgoingPackageCreator($state);

        $frame = $packageCreator->begin('tx1');

        $this->assertFrameEquals($expectedFrame, $frame);
    }

    /** @test */
    public function commitShouldEmitCommitFrame()
    {
        $expectedFrame = new Frame('COMMIT', ['transaction' => 'tx1']);

        $state = new State();
        $packageCreator = new OutgoingPackageCreator($state);

        $frame = $packageCreator->commit('tx1');

        $this->assertFrameEquals($expectedFrame, $frame);
    }

    /** @test */
    public function abortShouldEmitAbortFrame()
    {
        $expectedFrame = new Frame('ABORT', ['transaction' => 'tx1']);

        $state = new State();
        $packageCreator = new OutgoingPackageCreator($state);

        $frame = $packageCreator->abort('tx1');

        $this->assertFrameEquals($expectedFrame, $frame);
    }

    /** @test */
    public function disconnectShouldEmitDisconnectFrame()
    {
        $expectedFrame = new Frame('DISCONNECT', ['receipt' => 'foo']);

        $state = new State();
        $packageCreator = new OutgoingPackageCreator($state);

        $this->assertSame(State::STATUS_INIT, $state->status);

        $frame = $packageCreator->disconnect('foo');

        $this->assertFrameEquals($expectedFrame, $frame);
        $this->assertSame(State::STATUS_DISCONNECTING, $state->status);
    }

    private function assertFrameEquals(Frame $expected, Frame $frame)
    {
        $this->assertSame((string) $expected, (string) $frame);
    }

    private function createCallableMock()
    {
        return $this->createMock('React\Tests\Stomp\Stub\CallableStub');
    }
}
