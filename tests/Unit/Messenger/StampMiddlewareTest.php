<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Messenger;

use Msstc4Symfony\TracingBundle\Messenger\Middleware\IncomingStampMiddleware;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\OutgoingStampMiddleware;
use Msstc4Symfony\TracingBundle\Messenger\Stamp\RequestIdStamp;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

#[CoversClass(IncomingStampMiddleware::class)]
#[CoversClass(OutgoingStampMiddleware::class)]
#[UsesClass(RequestIdService::class)]
#[UsesClass(RequestIdStamp::class)]
final class StampMiddlewareTest extends TestCase
{
    private RequestIdService $storage;

    protected function setUp(): void
    {
        $this->storage = new RequestIdService('shop', 'worker');
    }

    public function testOutgoingStampsADispatchedMessage(): void
    {
        $this->storage->setRequestId('abc');

        $envelope = new OutgoingStampMiddleware($this->storage)->handle(new Envelope(new stdClass()), new StackMiddleware());

        self::assertEquals(new RequestIdStamp('abc', 'shop:worker'), $envelope->last(RequestIdStamp::class));
    }

    public function testOutgoingKeepsAnExistingStamp(): void
    {
        $envelope = new Envelope(new stdClass(), [new RequestIdStamp('origin', 'billing:api')]);

        $envelope = new OutgoingStampMiddleware($this->storage)->handle($envelope, new StackMiddleware());

        self::assertEquals(new RequestIdStamp('origin', 'billing:api'), $envelope->last(RequestIdStamp::class));
    }

    public function testIncomingRunsTheHandlerInTheSendersTrace(): void
    {
        $seen = null;
        $stack = $this->stackSeeing(function () use (&$seen): void {
            $seen = [$this->storage->getRequestId(), $this->storage->getRequestFrom()];
        });
        $envelope = new Envelope(new stdClass(), [new ReceivedStamp('async'), new RequestIdStamp('abc', 'billing:api')]);

        new IncomingStampMiddleware($this->storage)->handle($envelope, $stack);

        self::assertSame(['abc', 'billing:api'], $seen);
        // Kept for the worker's ack logs; WorkerTraceSubscriber clears it.
        self::assertSame('abc', $this->storage->getRequestId());
    }

    public function testIncomingLeavesSynchronousDispatchInTheCallersTrace(): void
    {
        $this->storage->setRequestId('http-request');

        new IncomingStampMiddleware($this->storage)->handle(
            new Envelope(new stdClass(), [new RequestIdStamp('abc', 'billing:api')]),
            new StackMiddleware(),
        );

        self::assertSame('http-request', $this->storage->getRequestId());
    }

    /**
     * @param callable(): void $probe
     */
    private function stackSeeing(callable $probe): StackInterface
    {
        return new StackMiddleware(new class($probe) implements MiddlewareInterface {
            /** @var callable(): void */
            private $probe;

            /**
             * @param callable(): void $probe
             */
            public function __construct(callable $probe)
            {
                $this->probe = $probe;
            }

            #[Override]
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->probe)();

                return $envelope;
            }
        });
    }
}
