<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Framework\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Trace of a dispatched message. W3C values travel as header strings: they serialize with every
 * Messenger serializer and are validated again on receipt.
 */
final readonly class TraceStamp implements StampInterface
{
    /**
     * @param non-empty-string $traceParent W3C traceparent header value
     * @param string|null $baggage W3C baggage header value
     */
    public function __construct(
        public string $traceParent,
        public ?string $traceState = null,
        public ?string $baggage = null,
    ) {
    }
}
