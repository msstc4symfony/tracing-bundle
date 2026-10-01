<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Messenger;

use Closure;
use Msstc4Symfony\TracingBundle\Messenger\EventListener\WorkerTraceSubscriber;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\IncomingStampMiddleware;
use Msstc4Symfony\TracingBundle\Messenger\Stamp\RequestIdStamp;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;
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

        self::assertSame(['received', 'received', 'flushed batched', 'received', 'stopped'], array_map(
            static fn (string $entry): string => explode(':', $entry)[0],
            $this->log,
        ));
        foreach ($this->log as $entry) {
            self::assertStringNotContainsString(':batched', $entry, implode(', ', $this->log));
        }
    }

    public function testIdleTicksKeepTheCommandTrace(): void
    {
        $this->storage->setRequestId('console-run');

        $this->consume(new MockClock(), [], [], idleTicks: 3);

        self::assertSame('console-run', $this->storage->getRequestId());
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

final class BatchedMessage
{
}

final class SlowMessage
{
}

final class BatchHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    /** @param Closure(string):void $onFlush */
    public function __construct(
        private readonly Closure $onFlush,
    ) {
    }

    public function __invoke(BatchedMessage $message, ?Acknowledger $ack = null): mixed
    {
        return $this->handle($message, $ack);
    }

    /**
     * @param list<array{object, Acknowledger}> $jobs
     */
    protected function process(array $jobs): void
    {
        foreach ($jobs as [, $ack]) {
            ($this->onFlush)('flushed');
            $ack->ack();
        }
    }

    protected function getBatchSize(): int
    {
        return 100;
    }
}
