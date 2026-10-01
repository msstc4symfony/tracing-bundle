<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Messenger;

use LogicException;
use Msstc4Symfony\TracingBundle\Messenger\EventListener\ResetTraceOnWorkerRunning;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\IncomingStampMiddleware;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\OutgoingStampMiddleware;
use Msstc4Symfony\TracingBundle\Messenger\Stamp\RequestIdStamp;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
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
#[CoversClass(IncomingStampMiddleware::class)]
#[CoversClass(OutgoingStampMiddleware::class)]
#[CoversClass(ResetTraceOnWorkerRunning::class)]
#[UsesClass(RequestIdService::class)]
#[UsesClass(RequestIdStamp::class)]
#[UsesClass(TraceContext::class)]
final class MessageBusTraceTest extends TestCase
{
    private RequestIdService $storage;

    private InMemoryTransport $async;

    /** @var array<string, array{string, string}> */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->storage = new RequestIdService('shop', 'api');
        $this->async = new InMemoryTransport();
    }

    public function testSyncTransportKeepsTheCallersTraceAfterDispatch(): void
    {
        $this->storage->setRequestId('http-abc')->setRequestFrom('billing:api');
        $runtimeId = $this->storage->getRuntimeId();

        $this->bus()->dispatch(new SyncMessage());

        self::assertSame(['http-abc', 'shop:api'], $this->seen[SyncMessage::class]);
        self::assertSame('http-abc', $this->storage->getRequestId());
        self::assertSame('billing:api', $this->storage->getRequestFrom());
        self::assertSame($runtimeId, $this->storage->getRuntimeId());
    }

    public function testConsumedMessageKeepsItsTraceForDeferredDispatchAndWorkerLogs(): void
    {
        $this->bus()->dispatch(new Envelope(new ConsumedMessage(), [
            new ReceivedStamp('async'),
            new RequestIdStamp('t1', 'billing:api'),
        ]));

        $deferred = $this->async->getSent()[0] ?? null;
        self::assertInstanceOf(Envelope::class, $deferred);
        self::assertSame('t1', $deferred->last(RequestIdStamp::class)?->requestId);
        self::assertSame('t1', $this->storage->getRequestId());

        new ResetTraceOnWorkerRunning($this->storage)->onWorkerRunning();

        self::assertNotSame('t1', $this->storage->getRequestId());
    }

    private function bus(): MessageBusInterface
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
                $this->seen[SyncMessage::class] = [$this->storage->getRequestId(), $this->storage->getRequestFrom()];
            }],
            ConsumedMessage::class => [function () use ($busProxy): void {
                $busProxy->dispatch(new DeferredMessage(), [new DispatchAfterCurrentBusStamp()]);
            }],
        ]);

        return $busProxy->bus = new MessageBus([
            new DispatchAfterCurrentBusMiddleware(),
            new OutgoingStampMiddleware($this->storage),
            new IncomingStampMiddleware($this->storage),
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
