<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * W3C Trace Context of a dispatched message, as header values: plain strings serialize with
 * every Messenger serializer and are validated again on receipt.
 *
 * A consumer without this class (tracing-bundle < 1.1) cannot decode the message, which is why
 * stamping it is opt-in (`w3c_trace_context.messenger`).
 */
final readonly class TraceContextStamp implements StampInterface
{
    /**
     * @param non-empty-string $traceParent
     * @param non-empty-string|null $traceState
     */
    public function __construct(
        public string $traceParent,
        public ?string $traceState = null,
    ) {
    }
}
