<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Trace of a dispatched message. W3C values travel as header strings: they serialize with every
 * Messenger serializer and are validated again on receipt.
 */
final readonly class TraceStamp implements StampInterface
{
    /** @param non-empty-string $traceParent W3C traceparent header value */
    public function __construct(
        public string $requestId,
        public string $requestFrom,
        public string $traceParent,
        public ?string $traceState = null,
    ) {
    }
}
