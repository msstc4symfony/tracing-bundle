<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Framework\Messenger;

use Closure;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
use Msstc4Symfony\TracingBundle\Framework\Messenger\IncomingTraceMiddleware;
use Msstc4Symfony\TracingBundle\Framework\Messenger\TraceStamp;
use Msstc4Symfony\TracingBundle\Framework\Messenger\WorkerTraceSubscriber;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use Msstc4Symfony\TracingBundle\Test\Unit\Framework\Messenger\Fixture\BatchedMessage;
use Msstc4Symfony\TracingBundle\Test\Unit\Framework\Messenger\Fixture\BatchHandler;
use Msstc4Symfony\TracingBundle\Test\Unit\Framework\Messenger\Fixture\SlowMessage;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

/**
 * A real Worker: the trace of a consumed message must not reach the next message's
 * WorkerMessageReceivedEvent listeners, even when a batch flush skips WorkerRunningEvent.
 */
#[CoversClass(IncomingTraceMiddleware::class)]
#[CoversClass(WorkerTraceSubscriber::class)]
#[UsesClass(InMemoryTraceContextHolder::class)]
#[UsesClass(TraceStamp::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(Baggage::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
final class WorkerTraceTest extends TestCase
{
    private const TRACE_PARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    private InMemoryTraceContextHolder $holder;

    /** @var list<string> */
    private array $log = [];

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(Worker::class)) {
            self::markTestSkipped('symfony/messenger is not installed');
        }

        if (!class_exists(MockClock::class)) {
            self::markTestSkipped('symfony/clock is not installed');
        }

        $this->holder = new InMemoryTraceContextHolder();
    }

    public function testBatchFlushedMessageTraceDoesNotLeakIntoTheNextMessage(): void
    {
        $clock = new MockClock();
        $this->consume($clock, [
            new Envelope(new BatchedMessage(), [new TraceStamp(self::TRACE_PARENT, null, 'label=batched')]),
            // Its handler outlasts the 30 s batch timeout, so the worker flushes after it.
            new Envelope(new SlowMessage(), [new TraceStamp(self::TRACE_PARENT, null, 'label=slow')]),
            new Envelope(new SlowMessage(), [new TraceStamp(self::TRACE_PARENT, null, 'label=next')]),
        ], [SlowMessage::class => [static function () use ($clock): void {
            $clock->sleep(31);
        }]]);

        self::assertContains('flushed batched:', $this->log);
        $this->assertTraceNeverLeaks('batched');
    }

    public function testBatchFlushedOnStopIsClosedBeforeTheWorkerStops(): void
    {
        $this->consume(new MockClock(), [
            new Envelope(new BatchedMessage(), [new TraceStamp(self::TRACE_PARENT, null, 'label=batched')]),
        ], []);

        self::assertContains('flushed batched:', $this->log);
        $this->assertTraceNeverLeaks('batched');
    }

    public function testRunningEventAfterAMessageIsOutsideItsTrace(): void
    {
        $this->consume(new MockClock(), [
            new Envelope(new SlowMessage(), [new TraceStamp(self::TRACE_PARENT, null, 'label=handled')]),
        ], [SlowMessage::class => [static function (): void {}]]);

        self::assertNotSame([], array_filter($this->log, static fn (string $entry): bool => str_starts_with($entry, 'running:')));
        $this->assertTraceNeverLeaks('handled');
    }

    public function testIdleTicksAfterAMessageKeepOneTrace(): void
    {
        $this->consume(new MockClock(), [
            new Envelope(new SlowMessage(), [new TraceStamp(self::TRACE_PARENT, null, 'label=handled')]),
        ], [SlowMessage::class => [static function (): void {}]], idleTicks: 3);

        $idle = array_values(array_filter($this->log, static fn (string $entry): bool => str_starts_with($entry, 'idle:')));
        self::assertCount(3, $idle);
        self::assertCount(1, array_unique($idle));
    }

    public function testIdleTicksKeepTheCommandTrace(): void
    {
        $this->holder->replace($this->holder->current()->withBaggage(Baggage::fromHeaders(['label=console-run'])));

        $this->consume(new MockClock(), [], [], idleTicks: 3);

        self::assertSame('console-run', $this->label());
    }

    public function testNextMessageDoesNotInheritTheW3cTrace(): void
    {
        $seen = [];
        $this->consume(new MockClock(), [
            new Envelope(new SlowMessage(), [new TraceStamp(self::TRACE_PARENT, 'rojo=1', 'label=stamped')]),
            new Envelope(new SlowMessage()),
        ], [SlowMessage::class => [function () use (&$seen): void {
            $seen[] = [$this->holder->current()->traceParent->traceId, $this->holder->current()->traceState?->value];
        }]]);

        self::assertSame(['4bf92f3577b34da6a3ce929d0e0e4736', 'rojo=1'], $seen[0] ?? null);
        self::assertNotSame('4bf92f3577b34da6a3ce929d0e0e4736', $seen[1][0] ?? null);
        self::assertNull($seen[1][1] ?? null);
    }

    public function testClosesTheUnitBeforeOtherReceivedListenersAndOnEveryWorkerTick(): void
    {
        self::assertSame(
            [
                WorkerMessageReceivedEvent::class => ['closeUnit', 4096],
                WorkerRunningEvent::class => 'closeUnit',
                WorkerStoppedEvent::class => 'closeUnit',
            ],
            WorkerTraceSubscriber::getSubscribedEvents(),
        );
    }

    private function assertTraceNeverLeaks(string $label): void
    {
        foreach ($this->log as $entry) {
            self::assertStringNotContainsString(':' . $label, $entry, implode(', ', $this->log));
        }
    }

    /**
     * @param list<Envelope> $envelopes
     * @param array<class-string, list<Closure(): void>> $handlers
     */
    private function consume(MockClock $clock, array $envelopes, array $handlers, int $idleTicks = 1): void
    {
        $incoming = new IncomingTraceMiddleware($this->holder);
        $batch = new BatchHandler(function (string $event): void {
            $this->log[] = $event . ' ' . $this->label() . ':';
        });
        $bus = new MessageBus([
            $incoming,
            new HandleMessageMiddleware(new HandlersLocator($handlers + [BatchedMessage::class => [new HandlerDescriptor($batch)]])),
        ]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new WorkerTraceSubscriber($incoming));
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, function (): void {
            $this->log[] = 'received:' . $this->label();
        });
        $dispatcher->addListener(WorkerRunningEvent::class, function (WorkerRunningEvent $event): void {
            $this->log[] = ($event->isWorkerIdle() ? 'idle:' : 'running:') . $this->label() . '|' . $this->holder->current()->runtimeId;
        }, -5);
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use (&$idleTicks): void {
            if ($event->isWorkerIdle() && --$idleTicks <= 0) {
                $event->getWorker()->stop();
            }
        }, -10);

        $receiver = new class($envelopes) implements ReceiverInterface {
            /** @param list<Envelope> $queue */
            public function __construct(private array $queue)
            {
            }

            #[Override]
            public function get(): iterable
            {
                $next = array_shift($this->queue);

                return $next === null ? [] : [$next];
            }

            #[Override]
            public function ack(Envelope $envelope): void
            {
            }

            #[Override]
            public function reject(Envelope $envelope): void
            {
            }
        };

        (new Worker(['queue' => $receiver], $bus, $dispatcher, null, null, $clock))->run(['sleep' => 0]);
        $this->log[] = 'stopped:' . $this->label();
    }

    /**
     * The trace a log line belongs to, named by the test through a baggage member.
     */
    private function label(): string
    {
        return $this->holder->current()->baggage->get('label') ?? '';
    }
}
