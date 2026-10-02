<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Messenger;

use Msstc4Symfony\TracingBundle\Messenger\Middleware\IncomingStampMiddleware;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\OutgoingStampMiddleware;
use Msstc4Symfony\TracingBundle\Messenger\Stamp\RequestIdStamp;
use Msstc4Symfony\TracingBundle\Messenger\Stamp\TraceContextStamp;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
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
#[UsesClass(TraceContextStamp::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
final class StampMiddlewareTest extends TestCase
{
    private const string TRACE_PARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00';

    private RequestIdService $storage;

    protected function setUp(): void
    {
        if (!class_exists(Envelope::class)) {
            self::markTestSkipped('symfony/messenger is not installed');
        }

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

    public function testOutgoingStampsTheW3cTraceWithANewSpanPerMessage(): void
    {
        $received = TraceParent::fromHeader(self::TRACE_PARENT);
        self::assertInstanceOf(TraceParent::class, $received);
        $this->storage->continueTrace($received, TraceState::fromHeaders(['rojo=1']));
        $middleware = new OutgoingStampMiddleware($this->storage, $this->storage);

        $first = $middleware->handle(new Envelope(new stdClass()), new StackMiddleware())->last(TraceContextStamp::class);
        $second = $middleware->handle(new Envelope(new stdClass()), new StackMiddleware())->last(TraceContextStamp::class);

        self::assertInstanceOf(TraceContextStamp::class, $first);
        self::assertInstanceOf(TraceContextStamp::class, $second);
        $firstParent = TraceParent::fromHeader($first->traceParent);
        $secondParent = TraceParent::fromHeader($second->traceParent);
        self::assertInstanceOf(TraceParent::class, $firstParent);
        self::assertInstanceOf(TraceParent::class, $secondParent);
        self::assertSame($received->traceId, $firstParent->traceId);
        self::assertSame($received->traceId, $secondParent->traceId);
        self::assertSame(0, $firstParent->flags);
        self::assertNotSame($firstParent->parentId, $secondParent->parentId);
        self::assertNotContains($firstParent->parentId, [$received->parentId, $this->storage->getTraceParent()->parentId]);
        self::assertSame('rojo=1', $first->traceState);
    }

    public function testOutgoingKeepsAnExistingTraceContextStamp(): void
    {
        $stamp = new TraceContextStamp(self::TRACE_PARENT);

        $envelope = new OutgoingStampMiddleware($this->storage, $this->storage)
            ->handle(new Envelope(new stdClass(), [$stamp]), new StackMiddleware())
        ;

        self::assertSame([$stamp], $envelope->all(TraceContextStamp::class));
    }

    public function testOutgoingLeavesReceivedMessagesAlone(): void
    {
        $envelope = new OutgoingStampMiddleware($this->storage, $this->storage)
            ->handle(new Envelope(new stdClass(), [new ReceivedStamp('async')]), new StackMiddleware())
        ;

        self::assertNull($envelope->last(TraceContextStamp::class));
    }

    public function testOutgoingAddsNoTraceContextStampWhenDisabled(): void
    {
        $envelope = new OutgoingStampMiddleware($this->storage)->handle(new Envelope(new stdClass()), new StackMiddleware());

        self::assertNull($envelope->last(TraceContextStamp::class));
    }

    public function testIncomingContinuesTheSendersW3cTrace(): void
    {
        $seen = null;
        $stack = $this->stackSeeing(function () use (&$seen): void {
            $seen = [$this->storage->getRemoteTraceParent()?->toHeader(), $this->storage->getTraceParent()->traceId, $this->storage->getTraceState()?->value];
        });
        $envelope = new Envelope(new stdClass(), [
            new ReceivedStamp('async'),
            new RequestIdStamp('abc', 'billing:api'),
            new TraceContextStamp(self::TRACE_PARENT, 'rojo=1'),
        ]);

        new IncomingStampMiddleware($this->storage, $this->storage)->handle($envelope, $stack);

        self::assertSame([self::TRACE_PARENT, '4bf92f3577b34da6a3ce929d0e0e4736', 'rojo=1'], $seen);
        self::assertSame('abc', $this->storage->getRequestId());
    }

    public function testIncomingDerivesTheRequestIdFromTheTraceIdWithoutARequestIdStamp(): void
    {
        $envelope = new Envelope(new stdClass(), [new ReceivedStamp('async'), new TraceContextStamp(self::TRACE_PARENT)]);

        new IncomingStampMiddleware($this->storage, $this->storage)->handle($envelope, new StackMiddleware());

        self::assertSame('4bf92f35-77b3-4da6-a3ce-929d0e0e4736', $this->storage->getRequestId());
        self::assertSame('unknown', $this->storage->getRequestFrom());
    }

    public function testIncomingIgnoresAnInvalidTraceParentAndItsTraceState(): void
    {
        $envelope = new Envelope(new stdClass(), [
            new ReceivedStamp('async'),
            new TraceContextStamp('00-00000000000000000000000000000000-00f067aa0ba902b7-01', 'rojo=1'),
        ]);

        new IncomingStampMiddleware($this->storage, $this->storage)->handle($envelope, new StackMiddleware());

        self::assertNull($this->storage->getRemoteTraceParent());
        self::assertNull($this->storage->getTraceState());
        self::assertSame('shop:worker', $this->storage->getRequestFrom());
    }

    public function testIncomingIgnoresTheTraceContextStampWhenDisabled(): void
    {
        $envelope = new Envelope(new stdClass(), [new ReceivedStamp('async'), new TraceContextStamp(self::TRACE_PARENT)]);

        new IncomingStampMiddleware($this->storage)->handle($envelope, new StackMiddleware());

        self::assertNull($this->storage->getRemoteTraceParent());
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
