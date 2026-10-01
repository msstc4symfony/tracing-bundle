<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Messenger\EventListener;

use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Clears a consumed message's trace once the worker has acked it and logged the outcome.
 */
final readonly class ResetTraceOnWorkerRunning implements EventSubscriberInterface
{
    public function __construct(
        private RequestIdServiceInterface $requestIdService,
    ) {
    }

    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [WorkerRunningEvent::class => 'onWorkerRunning'];
    }

    public function onWorkerRunning(): void
    {
        $this->requestIdService->reset();
    }
}
