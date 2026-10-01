<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Messenger;

use Closure;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\IncomingStampMiddleware;
use Msstc4Symfony\TracingBundle\Messenger\Stamp\RequestIdStamp;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
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
#[UsesClass(RequestIdStamp::class)]
#[UsesClass(TraceContext::class)]
final class UnitBoundaryTest extends TestCase
{
    private RequestIdService $storage;

    private IncomingStampMiddleware $incoming;

    protected function setUp(): void
    {
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
        return new Envelope(new stdClass(), [new ReceivedStamp('async'), new RequestIdStamp($requestId, 'billing:api')]);
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
