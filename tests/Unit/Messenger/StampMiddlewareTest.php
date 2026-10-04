<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Messenger;

use Msstc4Symfony\TracingBundle\Messenger\Middleware\IncomingStampMiddleware;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\OutgoingStampMiddleware;
use Msstc4Symfony\TracingBundle\Messenger\Stamp\TraceStamp;
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
#[UsesClass(TraceStamp::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
final class StampMiddlewareTest extends TestCase
{
    private const string TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const string TRACE_PARENT = '00-' . self::TRACE_ID . '-00f067aa0ba902b7-00';

    private RequestIdService $storage;

    protected function setUp(): void
    {
        if (!class_exists(Envelope::class)) {
            self::markTestSkipped('symfony/messenger is not installed');
        }

        $this->storage = new RequestIdService('shop', 'worker');
    }

    public function testOutgoingAddsOneTraceStamp(): void
    {
        $received = $this->receivedTraceParent();
        $this->storage->setRequestId('abc')->continueTrace($received, TraceState::fromHeaders(['rojo=1']));

        $envelope = new OutgoingStampMiddleware($this->storage)->handle(new Envelope(new stdClass()), new StackMiddleware());

        $stamps = $envelope->all(TraceStamp::class);
        self::assertCount(1, $stamps);
        $stamp = $stamps[0];
        self::assertInstanceOf(TraceStamp::class, $stamp);
        self::assertSame('abc', $stamp->requestId);
        self::assertSame('shop:worker', $stamp->requestFrom);
        self::assertSame('rojo=1', $stamp->traceState);
        $sent = TraceParent::fromHeader($stamp->traceParent);
        self::assertInstanceOf(TraceParent::class, $sent);
        self::assertSame($received->traceId, $sent->traceId);
        self::assertSame(0, $sent->flags);
        self::assertNotContains($sent->parentId, [$received->parentId, $this->storage->getTraceParent()->parentId]);
    }

    public function testOutgoingStartsANewSpanPerMessage(): void
    {
        $middleware = new OutgoingStampMiddleware($this->storage);

        $first = $middleware->handle(new Envelope(new stdClass()), new StackMiddleware())->last(TraceStamp::class);
        $second = $middleware->handle(new Envelope(new stdClass()), new StackMiddleware())->last(TraceStamp::class);

        self::assertInstanceOf(TraceStamp::class, $first);
        self::assertInstanceOf(TraceStamp::class, $second);
        self::assertNull($first->traceState);
        $firstParent = TraceParent::fromHeader($first->traceParent);
        $secondParent = TraceParent::fromHeader($second->traceParent);
        self::assertInstanceOf(TraceParent::class, $firstParent);
        self::assertInstanceOf(TraceParent::class, $secondParent);
        self::assertSame($firstParent->traceId, $secondParent->traceId);
        self::assertNotSame($firstParent->parentId, $secondParent->parentId);
    }

    public function testOutgoingKeepsAnExistingTraceStamp(): void
    {
        $stamp = new TraceStamp('origin', 'billing:api', self::TRACE_PARENT);

        $envelope = new OutgoingStampMiddleware($this->storage)->handle(new Envelope(new stdClass(), [$stamp]), new StackMiddleware());

        self::assertSame([$stamp], $envelope->all(TraceStamp::class));
    }

    public function testOutgoingLeavesReceivedMessagesAlone(): void
    {
        $received = new ReceivedStamp('async');

        $envelope = new OutgoingStampMiddleware($this->storage)->handle(new Envelope(new stdClass(), [$received]), new StackMiddleware());

        self::assertSame([], $envelope->all(TraceStamp::class));
        self::assertSame([$received], $envelope->all(ReceivedStamp::class));
    }

    public function testIncomingRunsTheHandlerInTheSendersTrace(): void
    {
        $seen = null;
        $stack = $this->stackSeeing(function () use (&$seen): void {
            $seen = [
                $this->storage->getRequestId(),
                $this->storage->getRequestFrom(),
                $this->storage->getRemoteTraceParent()?->toHeader(),
                $this->storage->getTraceParent()->traceId,
                $this->storage->getTraceState()?->value,
            ];
        });
        $envelope = new Envelope(new stdClass(), [
            new ReceivedStamp('async'),
            new TraceStamp('abc', 'billing:api', self::TRACE_PARENT, 'rojo=1'),
        ]);

        new IncomingStampMiddleware($this->storage)->handle($envelope, $stack);

        self::assertSame(['abc', 'billing:api', self::TRACE_PARENT, self::TRACE_ID, 'rojo=1'], $seen);
        // Kept for the worker's ack logs; WorkerTraceSubscriber clears it.
        self::assertSame('abc', $this->storage->getRequestId());
    }

    public function testIncomingLeavesSynchronousDispatchInTheCallersTrace(): void
    {
        $this->storage->setRequestId('http-request');
        $traceId = $this->storage->getTraceParent()->traceId;

        new IncomingStampMiddleware($this->storage)->handle(
            new Envelope(new stdClass(), [new TraceStamp('abc', 'billing:api', self::TRACE_PARENT)]),
            new StackMiddleware(),
        );

        self::assertSame('http-request', $this->storage->getRequestId());
        self::assertSame($traceId, $this->storage->getTraceParent()->traceId);
    }

    public function testIncomingDerivesTheRequestIdFromANewTraceWhenTheMessageIsNotStamped(): void
    {
        $this->storage->continueTrace($this->receivedTraceParent());

        new IncomingStampMiddleware($this->storage)->handle(new Envelope(new stdClass(), [new ReceivedStamp('async')]), new StackMiddleware());

        $traceParent = $this->storage->getTraceParent();
        self::assertNotSame(self::TRACE_ID, $traceParent->traceId);
        self::assertNull($this->storage->getRemoteTraceParent());
        self::assertSame($traceParent->toRequestId(), $this->storage->getRequestId());
        self::assertSame('shop:worker', $this->storage->getRequestFrom());
    }

    public function testIncomingIgnoresAnInvalidTraceParentAndItsTraceState(): void
    {
        $envelope = new Envelope(new stdClass(), [
            new ReceivedStamp('async'),
            new TraceStamp('abc', 'billing:api', '00-00000000000000000000000000000000-00f067aa0ba902b7-01', 'rojo=1'),
        ]);

        new IncomingStampMiddleware($this->storage)->handle($envelope, new StackMiddleware());

        self::assertNull($this->storage->getRemoteTraceParent());
        self::assertNull($this->storage->getTraceState());
        self::assertSame('abc', $this->storage->getRequestId());
        self::assertSame('billing:api', $this->storage->getRequestFrom());
    }

    private function receivedTraceParent(): TraceParent
    {
        $traceParent = TraceParent::fromHeader(self::TRACE_PARENT);
        self::assertInstanceOf(TraceParent::class, $traceParent);

        return $traceParent;
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
