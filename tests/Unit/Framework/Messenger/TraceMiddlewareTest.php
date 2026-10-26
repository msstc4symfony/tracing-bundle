<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Framework\Messenger;

use Msstc4Symfony\TracingBundle\Application\CallerName;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\OutgoingTrace;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\SampleRatio;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
use Msstc4Symfony\TracingBundle\Framework\Messenger\IncomingTraceMiddleware;
use Msstc4Symfony\TracingBundle\Framework\Messenger\OutgoingTraceMiddleware;
use Msstc4Symfony\TracingBundle\Framework\Messenger\TraceStamp;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
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

#[CoversClass(IncomingTraceMiddleware::class)]
#[CoversClass(OutgoingTraceMiddleware::class)]
#[UsesClass(InMemoryTraceContextHolder::class)]
#[UsesClass(CallerName::class)]
#[UsesClass(TraceStamp::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(OutgoingTrace::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
#[UsesClass(Baggage::class)]
#[UsesClass(SampleRatio::class)]
final class TraceMiddlewareTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const TRACE_PARENT = '00-' . self::TRACE_ID . '-00f067aa0ba902b7-00';

    private InMemoryTraceContextHolder $holder;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(Envelope::class)) {
            self::markTestSkipped('symfony/messenger is not installed');
        }

        $this->holder = new InMemoryTraceContextHolder();
    }

    public function testOutgoingAddsOneTraceStamp(): void
    {
        $this->holder->replace($this->holder->current()->continuing(
            $this->receivedTraceParent(),
            TraceState::fromHeaders(['rojo=1']),
            Baggage::fromHeaders(['user=alice']),
        ));

        $envelope = $this->outgoing()->handle(new Envelope(new stdClass()), new StackMiddleware());

        $stamps = $envelope->all(TraceStamp::class);
        self::assertCount(1, $stamps);
        $stamp = $stamps[0];
        self::assertInstanceOf(TraceStamp::class, $stamp);
        self::assertSame('rojo=1', $stamp->traceState);
        self::assertSame('msstc4symfony.caller=shop%3Aworker,user=alice', $stamp->baggage);
        $sent = TraceParent::fromHeader($stamp->traceParent);
        self::assertInstanceOf(TraceParent::class, $sent);
        self::assertSame(self::TRACE_ID, $sent->traceId);
        self::assertSame(0, $sent->flags);
        self::assertSame($this->holder->current()->traceParent->spanId, $sent->spanId);
    }

    public function testOutgoingSendsTheSameSpanWithEveryMessage(): void
    {
        $middleware = $this->outgoing();

        $first = $middleware->handle(new Envelope(new stdClass()), new StackMiddleware())->last(TraceStamp::class);
        $second = $middleware->handle(new Envelope(new stdClass()), new StackMiddleware())->last(TraceStamp::class);

        self::assertInstanceOf(TraceStamp::class, $first);
        self::assertInstanceOf(TraceStamp::class, $second);
        self::assertNull($first->traceState);
        self::assertSame($first->traceParent, $second->traceParent);
        self::assertSame($this->holder->current()->traceParent->toHeader(), $first->traceParent);
    }

    public function testOutgoingKeepsAnExistingTraceStamp(): void
    {
        $stamp = new TraceStamp(self::TRACE_PARENT);

        $envelope = $this->outgoing()->handle(new Envelope(new stdClass(), [$stamp]), new StackMiddleware());

        self::assertSame([$stamp], $envelope->all(TraceStamp::class));
    }

    public function testOutgoingLeavesReceivedMessagesAlone(): void
    {
        $received = new ReceivedStamp('async');

        $envelope = $this->outgoing()->handle(new Envelope(new stdClass(), [$received]), new StackMiddleware());

        self::assertSame([], $envelope->all(TraceStamp::class));
        self::assertSame([$received], $envelope->all(ReceivedStamp::class));
    }

    public function testConsumerContinuesTheStampedTraceAndBaggage(): void
    {
        $seen = $this->handleReceived(new Envelope(new stdClass(), [
            new ReceivedStamp('async'),
            new TraceStamp(self::TRACE_PARENT, 'rojo=1', 'msstc4symfony.caller=gateway%3Aweb'),
        ]));

        self::assertSame(self::TRACE_ID, $seen->traceParent->traceId);
        self::assertSame('00f067aa0ba902b7', $seen->parentSpanId);
        self::assertSame(self::TRACE_PARENT, $seen->remoteTraceParent?->toHeader());
        self::assertSame('rojo=1', $seen->traceState?->value);
        self::assertSame('gateway:web', $seen->caller());
        // Kept for the worker's ack logs; WorkerTraceSubscriber clears it.
        self::assertSame($seen, $this->holder->current());
    }

    public function testAnInvalidStampStartsANewTraceAndKeepsItsBaggage(): void
    {
        $seen = $this->handleReceived(new Envelope(new stdClass(), [
            new ReceivedStamp('async'),
            new TraceStamp('00-00000000000000000000000000000000-00f067aa0ba902b7-01', 'rojo=1', 'user=alice'),
        ]));

        self::assertNull($seen->remoteTraceParent);
        self::assertNull($seen->parentSpanId);
        self::assertNull($seen->traceState);
        self::assertSame('alice', $seen->baggage->get('user'));
    }

    public function testAnUnstampedMessageStartsANewUnitOfWork(): void
    {
        $this->holder->replace($this->holder->current()->continuing($this->receivedTraceParent(), null, Baggage::fromHeaders(['user=alice'])));
        $before = $this->holder->current();

        $seen = $this->handleReceived(new Envelope(new stdClass(), [new ReceivedStamp('async')]));

        self::assertNotSame($before->runtimeId, $seen->runtimeId);
        self::assertNotSame(self::TRACE_ID, $seen->traceParent->traceId);
        self::assertNull($seen->remoteTraceParent);
        self::assertTrue($seen->baggage->isEmpty());
    }

    public function testANewTraceOfAReceivedMessageFollowsTheRatio(): void
    {
        $unstamped = $this->handleReceived(new Envelope(new stdClass(), [new ReceivedStamp('async')]), new SampleRatio(0.0));
        $invalid = $this->handleReceived(new Envelope(new stdClass(), [
            new ReceivedStamp('async'),
            new TraceStamp('00-00000000000000000000000000000000-00f067aa0ba902b7-01'),
        ]), new SampleRatio(0.0));

        self::assertSame(TraceParent::FLAG_RANDOM, $unstamped->traceParent->flags);
        self::assertSame(TraceParent::FLAG_RANDOM, $invalid->traceParent->flags);
    }

    public function testAStampedTraceKeepsItsSampledFlagWhateverTheRatio(): void
    {
        $seen = $this->handleReceived(new Envelope(new stdClass(), [
            new ReceivedStamp('async'),
            new TraceStamp('00-' . self::TRACE_ID . '-00f067aa0ba902b7-01'),
        ]), new SampleRatio(0.0));

        self::assertSame(TraceParent::FLAG_SAMPLED, $seen->traceParent->flags);
    }

    public function testSynchronousDispatchLeavesTheCallersTraceAlone(): void
    {
        $before = $this->holder->current();

        (new IncomingTraceMiddleware($this->holder))->handle(
            new Envelope(new stdClass(), [new TraceStamp(self::TRACE_PARENT)]),
            new StackMiddleware(),
        );

        self::assertSame($before, $this->holder->current());
    }

    public function testNestedMessageRestoresTheOuterContext(): void
    {
        $outer = $this->holder->current()->withBaggage(Baggage::fromHeaders(['user=alice']));
        $this->holder->replace($outer);
        $middleware = new IncomingTraceMiddleware($this->holder);

        $unstamped = null;
        $stamped = null;
        // The outer unit is a message being handled: the nested ones arrive at depth > 0.
        $middleware->handle(new Envelope(new stdClass()), $this->stackSeeing(function () use ($middleware, &$unstamped, &$stamped): void {
            $middleware->handle(new Envelope(new stdClass(), [new ReceivedStamp('sync')]), $this->stackSeeing(function () use (&$unstamped): void {
                $unstamped = $this->holder->current();
            }));
            $middleware->handle(new Envelope(new stdClass(), [new ReceivedStamp('sync'), new TraceStamp(self::TRACE_PARENT)]), $this->stackSeeing(function () use (&$stamped): void {
                $stamped = $this->holder->current();
            }));
        }));

        self::assertInstanceOf(TraceContext::class, $unstamped);
        self::assertSame($outer->runtimeId, $unstamped->runtimeId);
        self::assertSame($outer->traceParent->traceId, $unstamped->traceParent->traceId);
        self::assertSame($outer->traceParent->spanId, $unstamped->parentSpanId);
        self::assertSame('alice', $unstamped->baggage->get('user'));
        self::assertInstanceOf(TraceContext::class, $stamped);
        self::assertSame(self::TRACE_ID, $stamped->traceParent->traceId);
        self::assertSame($outer, $this->holder->current());
    }

    private function outgoing(): OutgoingTraceMiddleware
    {
        return new OutgoingTraceMiddleware($this->holder, new CallerName('shop', 'worker'));
    }

    private function handleReceived(Envelope $envelope, ?SampleRatio $ratio = null): TraceContext
    {
        $seen = null;
        (new IncomingTraceMiddleware($this->holder, $ratio))->handle($envelope, $this->stackSeeing(function () use (&$seen): void {
            $seen = $this->holder->current();
        }));
        self::assertInstanceOf(TraceContext::class, $seen);

        return $seen;
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
