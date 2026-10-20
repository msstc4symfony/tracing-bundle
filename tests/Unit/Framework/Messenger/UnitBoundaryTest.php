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

/**
 * Only a message received at the top level opens a unit, and a unit is never closed from
 * inside handling.
 */
#[CoversClass(IncomingTraceMiddleware::class)]
#[UsesClass(InMemoryTraceContextHolder::class)]
#[UsesClass(TraceStamp::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(Baggage::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
final class UnitBoundaryTest extends TestCase
{
    private const TRACE_PARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    private InMemoryTraceContextHolder $holder;

    private IncomingTraceMiddleware $incoming;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(Envelope::class)) {
            self::markTestSkipped('symfony/messenger is not installed');
        }

        $this->holder = new InMemoryTraceContextHolder();
        $this->incoming = new IncomingTraceMiddleware($this->holder);
    }

    public function testMessageReceivedInsideAnotherDispatchDoesNotOpenAUnit(): void
    {
        $http = $this->holder->current();

        $this->incoming->handle(new Envelope(new stdClass()), $this->stack(function (): void {
            $this->incoming->handle($this->received(), new StackMiddleware());
        }));
        $this->incoming->closeUnit();

        self::assertSame($http, $this->holder->current());
    }

    public function testUnitIsNotClosedWhileItsMessageIsBeingHandled(): void
    {
        $before = null;
        $after = null;

        $this->incoming->handle($this->received(), $this->stack(function () use (&$before, &$after): void {
            $before = $this->holder->current();
            $this->incoming->closeUnit();
            $after = $this->holder->current();
        }));

        self::assertInstanceOf(TraceContext::class, $before);
        self::assertSame($before, $after);
    }

    private function received(): Envelope
    {
        return new Envelope(new stdClass(), [new ReceivedStamp('async'), new TraceStamp(self::TRACE_PARENT)]);
    }

    /**
     * @param Closure(): void $handler
     */
    private function stack(Closure $handler): StackMiddleware
    {
        return new StackMiddleware(new class($handler) implements MiddlewareInterface {
            /** @param Closure(): void $handler */
            public function __construct(private readonly Closure $handler)
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
