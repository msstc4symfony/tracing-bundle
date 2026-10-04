<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Storage;

use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;

/**
 * Snapshot of the trace, used to run a nested unit of work and restore the outer one.
 */
final readonly class TraceContext
{
    public function __construct(
        public string $runtimeId,
        public ?string $requestId,
        public ?string $requestFrom,
        public ?TraceParent $traceParent,
        public ?TraceParent $remoteTraceParent,
        public ?TraceState $traceState,
    ) {
    }
}
