<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Messenger;

use Closure;
use Msstc4Symfony\TracingBundle\Messenger\EventListener\WorkerTraceSubscriber;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\IncomingStampMiddleware;
use Msstc4Symfony\TracingBundle\Messenger\Stamp\RequestIdStamp;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
use Msstc4Symfony\TracingBundle\Test\Unit\Messenger\Fixture\BatchedMessage;
use Msstc4Symfony\TracingBundle\Test\Unit\Messenger\Fixture\BatchHandler;
use Msstc4Symfony\TracingBundle\Test\Unit\Messenger\Fixture\SlowMessage;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
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
#[CoversClass(IncomingStampMiddleware::class)]
#[CoversClass(WorkerTraceSubscriber::class)]
#[UsesClass(RequestIdService::class)]
#[UsesClass(RequestIdStamp::class)]
#[UsesClass(TraceContext::class)]
final class WorkerTraceTest extends TestCase
{
    private RequestIdService $storage;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        if (!class_exists(Worker::class)) {
            self::markTestSkipped('symfony/messenger is not installed');
        }

        if (!class_exists(MockClock::class)) {
            self::markTestSkipped('symfony/clock is not installed');
        }

        $this->storage = new RequestIdService('shop', 'worker');
    }

    public function testBatchFlushedMessageTraceDoesNotLeakIntoTheNextMessage(): void
    {
        $clock = new MockClock();
        $this->consume($clock, [
            new Envelope(new BatchedMessage(), [new RequestIdStamp('batched', 'x')]),
            // Its handler outlasts the 30 s batch timeout, so the worker flushes after it.
            new Envelope(new SlowMessage(), [new RequestIdStamp('slow', 'x')]),
            new Envelope(new SlowMessage(), [new RequestIdStamp('next', 'x')]),
        ], [SlowMessage::class => [static function () use ($clock): void {
            $clock->sleep(31);
        }]]);

        self::assertContains('flushed batched:', $this->log);
        $this->assertTraceNeverLeaks('batched');
    }

    public function testBatchFlushedOnStopIsClosedBeforeTheWorkerStops(): void
    {
        $this->consume(new MockClock(), [
            new Envelope(new BatchedMessage(), [new RequestIdStamp('batched', 'x')]),
        ], []);

        self::assertContains('flushed batched:', $this->log);
        $this->assertTraceNeverLeaks('batched');
    }

    public function testRunningEventAfterAMessageIsOutsideItsTrace(): void
    {
        $this->consume(new MockClock(), [
            new Envelope(new SlowMessage(), [new RequestIdStamp('handled', 'x')]),
        ], [SlowMessage::class => [static function (): void {}]]);

        self::assertNotSame([], array_filter($this->log, static fn (string $entry): bool => str_starts_with($entry, 'running:')));
        $this->assertTraceNeverLeaks('handled');
    }

    public function testIdleTicksAfterAMessageKeepOneTrace(): void
    {
        $this->consume(new MockClock(), [
            new Envelope(new SlowMessage(), [new RequestIdStamp('handled', 'x')]),
        ], [SlowMessage::class => [static function (): void {}]], idleTicks: 3);

        $idle = array_values(array_filter($this->log, static fn (string $entry): bool => str_starts_with($entry, 'idle:')));
        self::assertCount(3, $idle);
        self::assertCount(1, array_unique($idle));
    }

    public function testIdleTicksKeepTheCommandTrace(): void
    {
        $this->storage->setRequestId('console-run');

        $this->consume(new MockClock(), [], [], idleTicks: 3);

        self::assertSame('console-run', $this->storage->getRequestId());
    }

    private function assertTraceNeverLeaks(string $requestId): void
    {
        foreach ($this->log as $entry) {
            self::assertStringNotContainsString(':' . $requestId, $entry, implode(', ', $this->log));
        }
    }

    /**
     * @param list<Envelope> $envelopes
     * @param array<class-string, list<Closure(): void>> $handlers
     */
    private function consume(MockClock $clock, array $envelopes, array $handlers, int $idleTicks = 1): void
    {
        $incoming = new IncomingStampMiddleware($this->storage);
        $batch = new BatchHandler(function (string $event): void {
            $this->log[] = $event . ' ' . $this->storage->getRequestId() . ':';
        });
        $bus = new MessageBus([
            $incoming,
            new HandleMessageMiddleware(new HandlersLocator($handlers + [BatchedMessage::class => [new HandlerDescriptor($batch)]])),
        ]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new WorkerTraceSubscriber($incoming));
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, function (): void {
            $this->log[] = 'received:' . $this->storage->getRequestId();
        });
        $dispatcher->addListener(WorkerRunningEvent::class, function (WorkerRunningEvent $event): void {
            $this->log[] = ($event->isWorkerIdle() ? 'idle:' : 'running:') . $this->storage->getRequestId() . '|' . $this->storage->getRuntimeId();
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

        new Worker(['queue' => $receiver], $bus, $dispatcher, null, null, $clock)->run(['sleep' => 0]);
        $this->log[] = 'stopped:' . $this->storage->getRequestId();
    }
}
