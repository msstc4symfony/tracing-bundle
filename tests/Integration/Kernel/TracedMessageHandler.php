<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration\Kernel;

use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;

/**
 * Remembers the trace id each handled message ran in.
 */
final class TracedMessageHandler
{
    /** @var list<string> */
    public array $traceIds = [];

    public function __construct(
        private readonly RequestIdServiceInterface $storage,
    ) {
    }

    public function __invoke(): void
    {
        $this->traceIds[] = $this->storage->getTraceParent()->traceId;
    }
}
