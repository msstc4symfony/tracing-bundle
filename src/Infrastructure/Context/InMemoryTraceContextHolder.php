<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Infrastructure\Context;

use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\SampleRatio;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Override;

/**
 * @internal
 */
final class InMemoryTraceContextHolder implements TraceContextHolderInterface
{
    private TraceContext $context;

    public function __construct(
        private readonly SampleRatio $sampleRatio = new SampleRatio(1.0),
    ) {
        $this->context = TraceContext::start($this->sampleRatio);
    }

    #[Override]
    public function current(): TraceContext
    {
        return $this->context;
    }

    #[Override]
    public function replace(TraceContext $context): void
    {
        $this->context = $context;
    }

    #[Override]
    public function reset(): void
    {
        $this->context = TraceContext::start($this->sampleRatio);
    }
}
