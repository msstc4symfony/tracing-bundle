<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Infrastructure\Context;

use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryOutgoingSpanReservation;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryOutgoingSpanReservation::class)]
#[UsesClass(InMemoryTraceContextHolder::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(Baggage::class)]
final class InMemoryOutgoingSpanReservationTest extends TestCase
{
    private InMemoryTraceContextHolder $holder;

    private InMemoryOutgoingSpanReservation $reservation;

    #[Override]
    protected function setUp(): void
    {
        $this->holder = new InMemoryTraceContextHolder();
        $this->reservation = new InMemoryOutgoingSpanReservation($this->holder);
    }

    public function testReservesAChildSpanOfTheCurrentTrace(): void
    {
        $current = $this->holder->current();

        $reserved = $this->reservation->reserve();

        self::assertSame($current->traceParent->traceId, $reserved->traceParent->traceId);
        self::assertNotSame($current->traceParent->spanId, $reserved->traceParent->spanId);
        self::assertSame($current->traceParent->spanId, $reserved->parentSpanId);
        self::assertSame($current, $this->holder->current());
    }

    public function testTakeHandsOutTheReservationOnce(): void
    {
        $reserved = $this->reservation->reserve();

        self::assertSame($reserved, $this->reservation->take());
        self::assertNull($this->reservation->take());
    }

    public function testTakeWithoutAReservationIsNull(): void
    {
        self::assertNull($this->reservation->take());
    }

    public function testANewReservationReplacesTheOneNotTaken(): void
    {
        $this->reservation->reserve();
        $second = $this->reservation->reserve();

        self::assertSame($second, $this->reservation->take());
    }

    public function testAReservationOfAnotherTraceIsDropped(): void
    {
        $this->reservation->reserve();
        $this->holder->replace($this->holder->current()->withChildSpan());

        self::assertNull($this->reservation->take());
        $this->holder->replace(TraceContext::start());
        self::assertNull($this->reservation->take(), 'the reservation is gone, not kept for its trace');
    }

    public function testResetDropsTheReservation(): void
    {
        $this->reservation->reserve();

        $this->reservation->reset();

        self::assertNull($this->reservation->take());
    }
}
