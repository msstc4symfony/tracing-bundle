<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Infrastructure\Context;

use Msstc4Symfony\TracingBundle\Application\Port\OutgoingSpanReservationInterface;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Override;

/**
 * One slot: the HTTP decorators call their inner client synchronously, so a request passes all of them before the next
 * one starts. A later pass of the same request (a Guzzle redirect or retry from a promise) finds the slot empty.
 *
 * @internal
 */
final class InMemoryOutgoingSpanReservation implements OutgoingSpanReservationInterface
{
    private ?TraceContext $reservedFrom = null;

    private ?TraceContext $reserved = null;

    public function __construct(
        private readonly TraceContextHolderInterface $holder,
    ) {
    }

    #[Override]
    public function reserve(): TraceContext
    {
        $this->reservedFrom = $this->holder->current();
        $this->reserved = $this->reservedFrom->withChildSpan();

        return $this->reserved;
    }

    #[Override]
    public function take(): ?TraceContext
    {
        $reserved = $this->reservedFrom === $this->holder->current() ? $this->reserved : null;
        $this->reset();

        return $reserved;
    }

    #[Override]
    public function reset(): void
    {
        $this->reservedFrom = null;
        $this->reserved = null;
    }
}
