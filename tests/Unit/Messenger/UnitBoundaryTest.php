<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Messenger;

use Closure;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\IncomingStampMiddleware;
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

/**
 * Only a message received at the top level opens a unit, and a unit is never closed from
 * inside handling.
 */
#[CoversClass(IncomingStampMiddleware::class)]
#[UsesClass(RequestIdService::class)]
#[UsesClass(TraceStamp::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
final class UnitBoundaryTest extends TestCase
{
    private const string TRACE_PARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    private RequestIdService $storage;

    private IncomingStampMiddleware $incoming;

    protected function setUp(): void
    {
        if (!class_exists(Envelope::class)) {
            self::markTestSkipped('symfony/messenger is not installed');
        }

        $this->storage = new RequestIdService('shop', 'api');
        $this->incoming = new IncomingStampMiddleware($this->storage);
    }

    public function testMessageReceivedInsideAnotherDispatchDoesNotOpenAUnit(): void
    {
        $this->storage->setRequestId('http');

        $this->incoming->handle(new Envelope(new stdClass()), $this->stack(function (): void {
            $this->incoming->handle($this->received('sync'), new StackMiddleware());
        }));
        $this->incoming->closeUnit();

        self::assertSame('http', $this->storage->getRequestId());
    }

    public function testUnitIsNotClosedWhileItsMessageIsBeingHandled(): void
    {
        $seen = null;

        $this->incoming->handle($this->received('worker'), $this->stack(function () use (&$seen): void {
            $this->incoming->closeUnit();
            $seen = $this->storage->getRequestId();
        }));

        self::assertSame('worker', $seen);
    }

    private function received(string $requestId): Envelope
    {
        return new Envelope(new stdClass(), [new ReceivedStamp('async'), new TraceStamp($requestId, 'billing:api', self::TRACE_PARENT)]);
    }

    /**
     * @param Closure(): void $handler
     */
    private function stack(Closure $handler): StackMiddleware
    {
        return new StackMiddleware(new readonly class($handler) implements MiddlewareInterface {
            /** @param Closure(): void $handler */
            public function __construct(private Closure $handler)
            {
            }

            #[Override]
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->handler)();

                return $envelope;
            }
        });
    }
}
