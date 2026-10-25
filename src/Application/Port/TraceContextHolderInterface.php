<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Application\Port;

use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Override;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The trace of the current unit of work. A nested unit keeps the outer context, replaces it with
 * withChildSpan() and puts it back in a finally block.
 */
interface TraceContextHolderInterface extends ResetInterface
{
    public function current(): TraceContext;

    public function replace(TraceContext $context): void;

    /**
     * Starts a new unit of work with a new trace (the bundle's holder applies sampling.ratio).
     */
    #[Override]
    public function reset(): void;
}
