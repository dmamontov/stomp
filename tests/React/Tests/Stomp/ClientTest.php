<?php

namespace React\Tests\Stomp;

use React\Stomp\Client;
use React\Stomp\Io\InputStreamInterface;
use React\Stomp\Io\OutputStreamInterface;
use React\Stomp\Protocol\Frame;

/**
 * @internal
 * @coversNothing
 */
class ClientTest extends TestCase
{
    /** @test */
    public function connectShouldSendConnectFrame()
    {
        $input = $this->createInputStreamMock();

        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');
        $output
            ->expects($this->once())
            ->method('sendFrame')
            ->with($this->frameIsEqual(new Frame('CONNECT', ['accept-version' => '1.1', 'host' => 'localhost'])))
        ;

        $client = new Client($this->createLoopMock(), $input, $output, ['vhost' => 'localhost']);
        $client->connect();
    }

    /** @test */
    public function connectShouldReturnPromise()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = new Client($this->createLoopMock(), $input, $output, ['vhost' => 'localhost']);
        $promise = $client->connect();

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
        $promise->then($this->expectCallableNever());
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function connectShouldRejectMissingHostOrVhost()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');
        $client = new Client($this->createLoopMock(), $input, $output, []);
    }

    /** @test */
    public function connectTwiceShouldReturnTheSamePromise()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = new Client($this->createLoopMock(), $input, $output, ['vhost' => 'localhost']);
        $promise1 = $client->connect();
        $promise2 = $client->connect();

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise1);
        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise2);
        $this->assertSame($promise1, $promise2);
    }

    /** @test */
    public function disconnectThenConnectShouldReturnNewPromise()
    {
        $input = $this->createInputStreamMock();

        $connectFrame = new Frame('CONNECT', ['accept-version' => '1.1', 'host' => 'localhost']);

        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');
        $output
            ->expects($this->at(0))
            ->method('sendFrame')
            ->with($this->frameIsEqual($connectFrame))
        ;
        $output
            ->expects($this->at(2))
            ->method('sendFrame')
            ->with($this->frameIsEqual($connectFrame))
        ;

        $client = new Client($this->createLoopMock(), $input, $output, ['vhost' => 'localhost']);
        $promise1 = $client->connect();
        $client->disconnect();
        $promise2 = $client->connect();

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise1);
        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise2);
        $this->assertNotSame($promise1, $promise2);
    }

    /** @test */
    public function itShouldEmitConnectAfterHandshake()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = new Client($this->createLoopMockWithConnectionTimer(), $input, $output, ['vhost' => 'localhost']);
        $client->on('connect', $this->expectCallableOnce());
        $client->connect();

        $frame = new Frame('CONNECTED', ['session' => '1234', 'server' => 'React/alpha']);
        $input->emit('frame', [$frame]);
    }

    /** @test */
    public function itShouldRejectPromiseIfConnectionFails()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = new Client($this->createLoopMock(), $input, $output, ['vhost' => 'localhost']);
        $client->connect()
            ->then($this->expectCallableNever(), $this->expectCallableOnce())
        ;

        $frame = new Frame('ERROR', [], 'Invalid virtual host: /');
        $input->emit('frame', [$frame]);
    }

    /** @test */
    public function itShouldRejectPromiseIfConnectionTimeout()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $loop = $this->createLoopMock();
        $timeout = 30;

        $capturedInterval = $capturedCallback = null;

        $loop->expects($this->once())
            ->method('addTimer')
            ->with($this->equalTo($timeout), $this->isType('callable'))
            ->will($this->returnCallback(function ($interval, $callback) use (&$capturedInterval, &$capturedCallback) {
                $capturedInterval = $interval;
                $capturedCallback = $callback;
            }))
        ;

        $client = new Client($loop, $input, $output, ['vhost' => 'localhost']);
        $client->connect($timeout)
            ->then($this->expectCallableNever(), $this->expectCallableOnce())
        ;

        call_user_func($capturedCallback);
    }

    /** @test */
    public function timeoutThenConnectShouldReturnANewPromise()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $loop = $this->createLoopMock();
        $timeout = 30;

        $capturedInterval = $capturedCallback = null;

        $loop->expects($this->any())
            ->method('addTimer')
            ->will($this->returnCallback(function ($interval, $callback) use (&$capturedInterval, &$capturedCallback) {
                $capturedInterval = $interval;
                $capturedCallback = $callback;
            }))
        ;

        $client = new Client($loop, $input, $output, ['vhost' => 'localhost']);
        $promise1 = $client->connect($timeout);

        call_user_func($capturedCallback);

        $promise2 = $client->connect($timeout);

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise1);
        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise2);
        $this->assertNotSame($promise1, $promise2);
    }

    /** @test */
    public function itShouldCancelTimerOnConnection()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $timeout = 30;
        $signature = uniqid('signature');

        $loop = $this->createLoopMockWithConnectionTimer();

        $client = new Client($loop, $input, $output, ['vhost' => 'localhost']);
        $client->connect($timeout)
            ->then($this->expectCallableOnce())
        ;

        $frame = new Frame('CONNECTED', ['session' => '1234', 'server' => 'React/alpha']);
        $input->emit('frame', [$frame]);
    }

    /** @test */
    public function itShouldNotBeConnectedAfterConstructor()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = new Client($this->createLoopMock(), $input, $output, ['vhost' => 'localhost']);

        $this->assertFalse($client->isConnected());
    }

    /** @test */
    public function itShouldThrowAnExceptionOnConnectedFrameOutsideWindow()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');
        $loop = $this->createLoopMock();

        $errors = [];

        $client = new Client($loop, $input, $output, ['vhost' => 'localhost']);
        $client->on('error', function ($error) use (&$errors) { $errors[] = $error; });

        $frame = new Frame('CONNECTED', ['session' => '1234', 'server' => 'React/alpha']);
        $input->emit('frame', [$frame]);

        $this->assertCount(1, $errors);
        $this->assertInstanceOf('React\Stomp\Exception\InvalidFrameException', $errors[0]);
        $this->assertSame("Received 'CONNECTED' frame outside a connecting window.", $errors[0]->getMessage());
    }

    /** @test */
    public function itShouldBeConnectedWhenThePromiseIsResolved()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = new Client($this->createLoopMockWithConnectionTimer(), $input, $output, ['vhost' => 'localhost']);
        $client->connect();

        $frame = new Frame('CONNECTED', ['session' => '1234', 'server' => 'React/alpha']);
        $input->emit('frame', [$frame]);

        $this->assertTrue($client->isConnected());
    }

    /** @test */
    public function itShouldNotBeConnectedAfterDisconnection()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = new Client($this->createLoopMockWithConnectionTimer(), $input, $output, ['vhost' => 'localhost']);
        $client->connect();

        $frame = new Frame('CONNECTED', ['session' => '1234', 'server' => 'React/alpha']);
        $input->emit('frame', [$frame]);

        $client->disconnect();
        $this->assertFalse($client->isConnected());
    }

    /** @test */
    public function itShouldResolveConnectPromiseAfterHandshake()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = new Client($this->createLoopMockWithConnectionTimer(), $input, $output, ['vhost' => 'localhost']);
        $client->on('connect', $this->expectCallableOnce());
        $client
            ->connect()
            ->then($this->expectCallableOnce())
        ;

        $frame = new Frame('CONNECTED', ['session' => '1234', 'server' => 'React/alpha']);
        $input->emit('frame', [$frame]);
    }

    /** @test */
    public function itShouldChangeToConnectedStateWhenReceivingConnectedResponse()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = $this->getConnectedClient($input, $output);
    }

    /** @test */
    public function sendShouldSend()
    {
        $input = $this->createInputStreamMock();

        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');
        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('SEND', ['destination' => '/foo', 'content-length' => '5', 'content-type' => 'text/plain'], 'hello')
            ))
        ;

        $client = $this->getConnectedClient($input, $output);
        $client->send('/foo', 'hello');
    }

    /**
     * @test
     * @dataProvider provideAvailableAckMethods
     *
     * @param mixed $ack
     */
    public function subscribeWithAckMustIncludeAValidAckMethod($ack)
    {
        $callback = $this->createCallableMock();

        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('SUBSCRIBE', ['id' => 0, 'destination' => '/foo', 'ack' => $ack])
            ))
        ;

        $client = $this->getConnectedClient($input, $output);
        $client->subscribeWithAck('/foo', $ack, $callback);
    }

    public function provideAvailableAckMethods()
    {
        return [
            ['client'],
            ['client-individual'],
        ];
    }

    /** @test */
    public function subscribeHasUniqueIdHeader()
    {
        $callback = $this->createCallableMock();

        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameHasHeader('id', 0))
        ;

        $output
            ->expects($this->at(2))
            ->method('sendFrame')
            ->with($this->frameHasHeader('id', 1))
        ;

        $client = $this->getConnectedClient($input, $output);
        $client->subscribe('/foo', $callback);
        $client->subscribe('/bar', $callback);
    }

    /** @test */
    public function subscribeCanEmbedCustomHeader()
    {
        $callback = $this->createCallableMock();

        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('SUBSCRIBE', ['foo' => 'bar', 'id' => 0, 'destination' => '/foo', 'ack' => 'auto'])
            ))
        ;

        $client = $this->getConnectedClient($input, $output);
        $client->subscribe('/foo', $callback, ['foo' => 'bar']);
    }

    /**
     * @test
     * @expectedException \LogicException
     */
    public function subscribeWithAckDoesNotWorkWithAutoAckMode()
    {
        $callback = $this->createCallableMock();

        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = $this->getConnectedClient($input, $output);
        $client->subscribeWithAck('/foo', 'auto', $callback);
    }

    /**
     * @test
     * @dataProvider provideAcknowledgeableAckModes
     *
     * @param mixed $ack
     */
    public function subscribeWithAckCallbackShouldHaveAckResolverArgument($ack)
    {
        $capturedResolver = null;

        $callback = $this->createCallableMock();
        $callback
            ->expects($this->exactly(1))
            ->method('__invoke')
            ->will($this->returnCallback(function ($frame, $resolver) use (&$capturedResolver) {
                $capturedResolver = $resolver;
            }))
        ;

        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = $this->getConnectedClient($input, $output);
        $subscriptionId = $client->subscribeWithAck('/foo', $ack, $callback);

        $responseFrame = new Frame(
            'MESSAGE',
            ['subscription' => $subscriptionId, 'message-id' => 42, 'destination' => '/foo', 'content-type' => 'text/plain'],
            'this is a published message'
        );
        $input->emit('frame', [$responseFrame]);

        $this->assertInstanceOf('React\Stomp\AckResolver', $capturedResolver);
    }

    public function provideAcknowledgeableAckModes()
    {
        return [
            ['client'],
            ['client-individual'],
        ];
    }

    /** @test */
    public function acknowledgeWithAckResolverArgumentShouldSendAckFrame()
    {
        $capturedResolver = null;

        $callback = $this->createCallableMock();
        $callback
            ->expects($this->exactly(1))
            ->method('__invoke')
            ->will($this->returnCallback(function ($frame, $resolver) use (&$capturedResolver) {
                $capturedResolver = $resolver;
            }))
        ;

        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $output
            ->expects($this->at(2))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('ACK', ['subscription' => 0, 'message-id' => 54321])
            ))
        ;

        $client = $this->getConnectedClient($input, $output);
        $subscriptionId = $client->subscribeWithAck('/foo', 'client', $callback);

        $responseFrame = new Frame(
            'MESSAGE',
            ['subscription' => $subscriptionId, 'message-id' => 54321, 'destination' => '/foo', 'content-type' => 'text/plain'],
            'this is a published message'
        );
        $input->emit('frame', [$responseFrame]);

        $capturedResolver->ack();
    }

    /** @test */
    public function negativeAcknowledgeWithAckResolverArgumentShouldSendNackFrame()
    {
        $capturedResolver = null;

        $callback = $this->createCallableMock();
        $callback
            ->expects($this->exactly(1))
            ->method('__invoke')
            ->will($this->returnCallback(function ($frame, $resolver) use (&$capturedResolver) {
                $capturedResolver = $resolver;
            }))
        ;

        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $output
            ->expects($this->at(2))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('NACK', ['subscription' => 0, 'message-id' => 54321])
            ))
        ;

        $client = $this->getConnectedClient($input, $output);
        $subscriptionId = $client->subscribeWithAck('/foo', 'client', $callback);

        $responseFrame = new Frame(
            'MESSAGE',
            ['subscription' => $subscriptionId, 'message-id' => 54321, 'destination' => '/foo', 'content-type' => 'text/plain'],
            'this is a published message'
        );
        $input->emit('frame', [$responseFrame]);

        $capturedResolver->nack();
    }

    /** @test */
    public function messagesShouldGetRoutedToSubscriptions()
    {
        $capturedFrame = null;

        $callback = $this->createCallableMock();
        $callback
            ->expects($this->exactly(2))
            ->method('__invoke')
            ->will($this->returnCallback(function ($frame) use (&$capturedFrame) {
                $capturedFrame = $frame;
            }))
        ;

        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = $this->getConnectedClient($input, $output);
        $subscriptionId = $client->subscribe('/foo', $callback);

        $responseFrame = new Frame(
            'MESSAGE',
            ['subscription' => $subscriptionId, 'message-id' => 42, 'destination' => '/foo', 'content-type' => 'text/plain'],
            'this is a published message'
        );
        $input->emit('frame', [$responseFrame]);

        $responseFrame = new Frame(
            'MESSAGE',
            ['subscription' => $subscriptionId, 'message-id' => 43, 'destination' => '/foo', 'content-type' => 'text/plain'],
            'this is a published message'
        );
        $input->emit('frame', [$responseFrame]);

        $this->assertInstanceOf('React\Stomp\Protocol\Frame', $capturedFrame);
        $this->assertFrameEquals($responseFrame, $capturedFrame);

        return [$input, $output, $client, $capturedFrame];
    }

    /**
     * @test
     * @depends messagesShouldGetRoutedToSubscriptions
     *
     * @param mixed $data
     */
    public function callbackShouldNotBeCalledAfterUnsubscribe($data)
    {
        list($input, $output, $client, $capturedFrame) = $data;

        $client->unsubscribe($capturedFrame->getHeader('subscription'));

        $responseFrame = new Frame(
            'MESSAGE',
            ['subscription' => 0, 'message-id' => 42, 'destination' => '/foo', 'content-type' => 'text/plain'],
            'this is a published message'
        );
        $input->emit('frame', [$responseFrame]);
    }

    /** @test */
    public function ackShouldSendAckFrame()
    {
        $input = $this->createInputStreamMock();

        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');
        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('ACK', ['subscription' => 12345, 'message-id' => 54321])
            ))
        ;

        $client = $this->getConnectedClient($input, $output);
        $client->ack(12345, 54321);
    }

    /** @test */
    public function ackShouldSendAckFrameWithCustomHeaders()
    {
        $input = $this->createInputStreamMock();

        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');
        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('ACK', ['foo' => 'bar', 'subscription' => 12345, 'message-id' => 54321])
            ))
        ;

        $client = $this->getConnectedClient($input, $output);
        $client->ack(12345, 54321, ['foo' => 'bar']);
    }

    /** @test */
    public function nackShouldSendNackFrame()
    {
        $input = $this->createInputStreamMock();

        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');
        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('NACK', ['subscription' => 12345, 'message-id' => 54321])
            ))
        ;

        $client = $this->getConnectedClient($input, $output);
        $client->nack(12345, 54321);
    }

    /** @test */
    public function nackShouldSendNackFrameWithCustomHeaders()
    {
        $input = $this->createInputStreamMock();

        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');
        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('NACK', ['foo' => 'bar', 'subscription' => 12345, 'message-id' => 54321])
            ))
        ;

        $client = $this->getConnectedClient($input, $output);
        $client->nack(12345, 54321, ['foo' => 'bar']);
    }

    /** @test */
    public function disconnectShouldGracefullyDisconnect()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = $this->getMockBuilder('React\Stomp\Client')
            ->setConstructorArgs([$this->createLoopMock(), $input, $output, ['vhost' => 'localhost']])
            ->setMethods(['generateReceiptId'])
            ->getMock()
        ;
        $client
            ->expects($this->once())
            ->method('generateReceiptId')
            ->will($this->returnValue('1234'))
        ;
        $input->emit('frame', [new Frame('CONNECTED')]);
        $client->disconnect();

        $output
            ->expects($this->once())
            ->method('close')
        ;

        $input->emit('frame', [new Frame('RECEIPT', ['receipt-id' => '1234'])]);
    }

    /** @test */
    public function processingErrorShouldResultInClientError()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = $this->getConnectedClient($input, $output);
        $client->on('error', $this->expectCallableOnce());

        $input->emit('frame', [new Frame('ERROR', ['message' => 'whoops'])]);
    }

    /** @test */
    public function inputErrorShouldResultInClientError()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = $this->getConnectedClient($input, $output);
        $client->on('error', $this->expectCallableOnce());

        $e = new \Exception('Input had a problem.');
        $input->emit('error', [$e]);
    }

    /** @test */
    public function inputCloseShouldResultInClientClose()
    {
        $input = $this->createInputStreamMock();
        $output = $this->createMock('React\Stomp\Io\OutputStreamInterface');

        $client = $this->getConnectedClient($input, $output);
        $client->on('close', $this->expectCallableOnce());

        $input->emit('close');
    }

    private function getConnectedClient(InputStreamInterface $input, OutputStreamInterface $output)
    {
        $client = new Client($this->createLoopMockWithConnectionTimer(), $input, $output, ['vhost' => 'localhost']);
        $client->connect();
        $input->emit('frame', [new Frame('CONNECTED')]);

        return $client;
    }

    private function createInputStreamMock()
    {
        return $this->getMockBuilder('React\Stomp\Io\InputStream')
            ->setConstructorArgs([$this->createMock('React\Stomp\Protocol\Parser')])
            ->setMethods(['isWritable', 'write', 'end', 'close'])
            ->getMock()
        ;
    }

    private function createLoopMock()
    {
        return $this->createMock('React\EventLoop\LoopInterface');
    }

    private function createLoopMockWithConnectionTimer()
    {
        $loop = $this->createLoopMock();

        $timer = $this->createMock('React\EventLoop\Timer\TimerInterface');
        $timer->expects($this->once())
            ->method('cancel')
        ;

        $loop->expects($this->once())
            ->method('addTimer')
            ->will($this->returnValue($timer))
        ;

        return $loop;
    }
}
