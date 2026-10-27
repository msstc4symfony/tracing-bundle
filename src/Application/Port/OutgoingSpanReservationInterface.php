<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Application\Port;

use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Override;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The span the next outgoing request sends in its trace headers. Whoever opens a client span for a request before it
 * goes out (tracing-bridge-profiling) reserves it; the bundle's HTTP integrations take it, so the receiver becomes a
 * child of that client span. Without a reservation they send the current span, as before.
 */
interface OutgoingSpanReservationInterface extends ResetInterface
{
    /**
     * A child span of the current trace, kept for the next outgoing request; replaces a reservation not taken. Call it
     * right before the request goes out, and reset() when it does not: the next request of the trace would send it.
     */
    public function reserve(): TraceContext;

    /**
     * The reservation, if it was made from the trace current now; clears it either way.
     */
    public function take(): ?TraceContext;

    #[Override]
    public function reset(): void;
}
