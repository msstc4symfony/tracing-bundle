<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Storage;

use InvalidArgumentException;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;

/**
 * Snapshot of the trace, used to run a nested unit of work and restore the outer one.
 */
final readonly class TraceContext
{
    /**
     * @param non-empty-string|null $parentSpanId span id of the unit that started this one; null at the root of the trace
     *
     * @throws InvalidArgumentException when $parentSpanId is not a W3C span id
     */
    public function __construct(
        public string $runtimeId,
        public ?string $requestId,
        public ?string $requestFrom,
        public ?TraceParent $traceParent,
        public ?TraceParent $remoteTraceParent,
        public ?TraceState $traceState,
        public ?string $parentSpanId = null,
    ) {
        if ($parentSpanId !== null && !TraceParent::isSpanId($parentSpanId)) {
            throw new InvalidArgumentException(sprintf('Parent span id "%s" is not a W3C span id.', $parentSpanId));
        }
    }
}
