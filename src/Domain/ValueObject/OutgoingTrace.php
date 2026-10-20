<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Domain\ValueObject;

/**
 * What a unit of work sends with an outgoing request or message.
 */
final readonly class OutgoingTrace
{
    public function __construct(
        public TraceParent $traceParent,
        public ?TraceState $traceState,
        public Baggage $baggage,
    ) {
    }
}
