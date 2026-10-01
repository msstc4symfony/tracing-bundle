<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Storage;

/**
 * Snapshot of the trace, used to run a nested unit of work and restore the outer one.
 */
final readonly class TraceContext
{
    public function __construct(
        public string $runtimeId,
        public ?string $requestId,
        public ?string $requestFrom,
    ) {
    }
}
