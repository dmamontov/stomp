<?php

namespace React\Functional\Stomp;

/**
 * @internal
 * @coversNothing
 */
class ConnectionTest extends FunctionalTestCase
{
    /** @test */
    public function itShouldConnect()
    {
        $loop = $this->getEventLoop();
        $client = $this->getClient($loop);

        $phpunit = $this;
        $connected = false;

        $client
            ->connect()
            ->then(function () use ($loop, &$connected) {
                $connected = true;
                $loop->stop();
            }, function (\Exception $e) use ($phpunit, $loop) {
                $loop->stop();
                $phpunit->fail('Connection should occur');
            })
        ;

        $loop->run();

        $this->assertTrue($connected);
    }

    /** @test */
    public function itShouldFailOnConnect()
    {
        if ('1' === getenv('SKIP_AUTH_CHECKS')) {
            return;
        }

        $loop = $this->getEventLoop();
        $client = $this->getClient($loop, [
            'login' => 'badidealogin',
            'passcode' => 'blegh',
        ]);

        $phpunit = $this;
        $error = null;

        $client
            ->connect()
            ->then(function () use ($phpunit, $loop) {
                $loop->stop();
                $phpunit->fail('Connection should occur');
            }, function ($e) use ($loop, &$error) {
                $error = $e;
                $loop->stop();
            })
        ;

        $loop->run();

        $this->assertInstanceOf('Exception', $error);
    }
}
