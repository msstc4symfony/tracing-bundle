<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration\Kernel;

use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;

/**
 * Remembers the trace id each handled message ran in.
 */
final class TracedMessageHandler
{
    /** @var list<string> */
    public array $traceIds = [];

    public function __construct(
        private readonly TraceContextHolderInterface $holder,
    ) {
    }

    public function __invoke(): void
    {
        $this->traceIds[] = $this->holder->current()->traceParent->traceId;
    }
}
