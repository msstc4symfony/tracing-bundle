<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Framework\Messenger;

use LogicException;
use Msstc4Symfony\TracingBundle\Application\CallerName;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\OutgoingTrace;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
use Msstc4Symfony\TracingBundle\Framework\Messenger\IncomingTraceMiddleware;
use Msstc4Symfony\TracingBundle\Framework\Messenger\OutgoingTraceMiddleware;
use Msstc4Symfony\TracingBundle\Framework\Messenger\TraceStamp;
use Msstc4Symfony\TracingBundle\Framework\Messenger\WorkerTraceSubscriber;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\DispatchAfterCurrentBusMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

/**
 * The bus as FrameworkBundle builds it: dispatch_after_current_bus first, then the tracing
 * middleware, then send and handle.
 */
#[CoversClass(IncomingTraceMiddleware::class)]
#[CoversClass(OutgoingTraceMiddleware::class)]
#[CoversClass(WorkerTraceSubscriber::class)]
#[UsesClass(InMemoryTraceContextHolder::class)]
#[UsesClass(CallerName::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(OutgoingTrace::class)]
#[UsesClass(TraceStamp::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
#[UsesClass(Baggage::class)]
final class MessageBusTraceTest extends TestCase
{
    private const TRACE_PARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    private InMemoryTraceContextHolder $holder;

    private InMemoryTransport $async;

    private IncomingTraceMiddleware $incoming;

    private ?TraceContext $seen = null;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(MessageBus::class)) {
            self::markTestSkipped('symfony/messenger is not installed');
        }

        $this->holder = new InMemoryTraceContextHolder();
        $this->async = new InMemoryTransport();
        $this->incoming = new IncomingTraceMiddleware($this->holder);
    }

    public function testSyncTransportContinuesTheTraceAndRestoresTheCallersContext(): void
    {
        $outer = $this->holder->current()->withBaggage(Baggage::fromHeaders(['user=alice']));
        $this->holder->replace($outer);

        $this->bus()->dispatch(new SyncMessage());

        self::assertInstanceOf(TraceContext::class, $this->seen);
        self::assertSame($outer->traceParent->traceId, $this->seen->traceParent->traceId);
        self::assertSame($outer->traceParent->spanId, $this->seen->parentSpanId);
        self::assertSame('shop:api', $this->seen->caller());
        self::assertSame('alice', $this->seen->baggage->get('user'));
        self::assertSame($outer, $this->holder->current());
    }

    public function testSyncMessageRunsAsAChildOfTheCallerAndTheCallersParentComesBack(): void
    {
        $received = TraceParent::fromHeader(self::TRACE_PARENT);
        self::assertInstanceOf(TraceParent::class, $received);
        $this->holder->replace($this->holder->current()->continuing($received, null, Baggage::empty()));
        $own = $this->holder->current()->traceParent;

        $this->bus()->dispatch(new SyncMessage());

        self::assertSame($own->spanId, $this->seen?->parentSpanId);
        self::assertSame('00f067aa0ba902b7', $this->holder->current()->parentSpanId);
    }

    public function testUnstampedSyncMessageIsAChildSpanOfTheCallersUnit(): void
    {
        $outer = $this->holder->current()->withBaggage(Baggage::fromHeaders(['user=alice']));
        $this->holder->replace($outer);

        $this->bus(stamp: false)->dispatch(new SyncMessage());

        self::assertInstanceOf(TraceContext::class, $this->seen);
        self::assertSame($outer->runtimeId, $this->seen->runtimeId);
        self::assertSame($outer->traceParent->traceId, $this->seen->traceParent->traceId);
        self::assertSame($outer->traceParent->spanId, $this->seen->parentSpanId);
        self::assertSame('alice', $this->seen->baggage->get('user'));
        self::assertSame($outer, $this->holder->current());
    }

    public function testConsumedMessageKeepsItsTraceForDeferredDispatchAndWorkerLogs(): void
    {
        $this->bus()->dispatch(new Envelope(new ConsumedMessage(), [
            new ReceivedStamp('async'),
            new TraceStamp(self::TRACE_PARENT),
        ]));

        $deferred = $this->async->getSent()[0] ?? null;
        self::assertInstanceOf(Envelope::class, $deferred);
        $stamp = $deferred->last(TraceStamp::class);
        self::assertInstanceOf(TraceStamp::class, $stamp);
        self::assertSame(TraceParent::fromHeader($stamp->traceParent)?->traceId, '4bf92f3577b34da6a3ce929d0e0e4736');
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $this->holder->current()->traceParent->traceId);

        (new WorkerTraceSubscriber($this->incoming))->closeUnit();

        self::assertNotSame('4bf92f3577b34da6a3ce929d0e0e4736', $this->holder->current()->traceParent->traceId);
    }

    private function bus(bool $stamp = true): MessageBusInterface
    {
        // SyncTransport needs the bus it belongs to; the proxy closes that loop.
        $busProxy = new class implements MessageBusInterface {
            public ?MessageBusInterface $bus = null;

            #[Override]
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return ($this->bus ?? throw new LogicException('Bus not built yet'))->dispatch($message, $stamps);
            }
        };

        $senders = new SendersLocator(
            [SyncMessage::class => ['sync'], DeferredMessage::class => ['async']],
            new ServiceLocator([
                'sync' => static fn (): SyncTransport => new SyncTransport($busProxy),
                'async' => fn (): InMemoryTransport => $this->async,
            ]),
        );

        $handlers = new HandlersLocator([
            SyncMessage::class => [function (): void {
                $this->seen = $this->holder->current();
            }],
            ConsumedMessage::class => [function () use ($busProxy): void {
                $busProxy->dispatch(new DeferredMessage(), [new DispatchAfterCurrentBusStamp()]);
            }],
        ]);

        return $busProxy->bus = new MessageBus([
            new DispatchAfterCurrentBusMiddleware(),
            ...($stamp ? [new OutgoingTraceMiddleware($this->holder, new CallerName('shop', 'api'))] : []),
            $this->incoming,
            new SendMessageMiddleware($senders),
            new HandleMessageMiddleware($handlers),
        ]);
    }
}

final class SyncMessage extends stdClass
{
}

final class ConsumedMessage extends stdClass
{
}

final class DeferredMessage extends stdClass
{
}
