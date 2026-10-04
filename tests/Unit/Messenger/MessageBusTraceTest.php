<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Messenger;

use LogicException;
use Msstc4Symfony\TracingBundle\Messenger\EventListener\WorkerTraceSubscriber;
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
#[CoversClass(WorkerTraceSubscriber::class)]
#[UsesClass(RequestIdService::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(TraceStamp::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
final class MessageBusTraceTest extends TestCase
{
    private RequestIdService $storage;

    private InMemoryTransport $async;

    private IncomingStampMiddleware $incoming;

    /** @var array<string, array{string, string}> */
    private array $seen = [];

    /** @var array<string, array{?string, string}> */
    private array $seenW3c = [];

    protected function setUp(): void
    {
        if (!class_exists(MessageBus::class)) {
            self::markTestSkipped('symfony/messenger is not installed');
        }

        $this->storage = new RequestIdService('shop', 'api');
        $this->async = new InMemoryTransport();
        $this->incoming = new IncomingStampMiddleware($this->storage);
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

    public function testSyncTransportContinuesTheW3cTraceAndRestoresTheCallersSpan(): void
    {
        $own = $this->storage->getTraceParent();

        $this->bus()->dispatch(new SyncMessage());

        [$parentId, $traceId] = $this->seenW3c[SyncMessage::class] ?? [null, null];
        self::assertSame($own->traceId, $traceId);
        self::assertNotNull($parentId);
        self::assertNotSame($own->parentId, $parentId);
        self::assertSame($own, $this->storage->getTraceParent());
        self::assertNull($this->storage->getRemoteTraceParent());
    }

    public function testSyncTransportKeepsTheCallersTraceWhenMessagesAreNotStamped(): void
    {
        $this->storage->setRequestId('http-abc')->setRequestFrom('billing:api');

        $this->bus(stamp: false)->dispatch(new SyncMessage());

        [$parentId, $traceId] = $this->seenW3c[SyncMessage::class] ?? [null, null];
        $own = $this->storage->getTraceParent();
        self::assertSame($own->traceId, $traceId);
        self::assertSame($own->parentId, $parentId);
        self::assertSame(['http-abc', 'billing:api'], $this->seen[SyncMessage::class] ?? null);
    }

    public function testUnstampedSyncMessageSharesTheRequestIdTheCallerHasNotReadYet(): void
    {
        $this->bus(stamp: false)->dispatch(new SyncMessage());

        self::assertSame($this->storage->getRequestId(), $this->seen[SyncMessage::class][0] ?? null);
    }

    public function testConsumedMessageKeepsItsTraceForDeferredDispatchAndWorkerLogs(): void
    {
        $this->bus()->dispatch(new Envelope(new ConsumedMessage(), [
            new ReceivedStamp('async'),
            new TraceStamp('t1', 'billing:api', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'),
        ]));

        $deferred = $this->async->getSent()[0] ?? null;
        self::assertInstanceOf(Envelope::class, $deferred);
        self::assertSame('t1', $deferred->last(TraceStamp::class)?->requestId);
        self::assertSame('t1', $this->storage->getRequestId());

        new WorkerTraceSubscriber($this->incoming)->closeUnit();

        self::assertNotSame('t1', $this->storage->getRequestId());
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
                $this->seen[SyncMessage::class] = [$this->storage->getRequestId(), $this->storage->getRequestFrom()];
                $this->seenW3c[SyncMessage::class] = [$this->storage->getRemoteTraceParent()?->parentId, $this->storage->getTraceParent()->traceId];
            }],
            ConsumedMessage::class => [function () use ($busProxy): void {
                $busProxy->dispatch(new DeferredMessage(), [new DispatchAfterCurrentBusStamp()]);
            }],
        ]);

        return $busProxy->bus = new MessageBus([
            new DispatchAfterCurrentBusMiddleware(),
            ...($stamp ? [new OutgoingStampMiddleware($this->storage)] : []),
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
