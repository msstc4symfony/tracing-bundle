<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Framework\Messenger;

use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Closes a consumed message's trace once the worker is done with it. WorkerRunningEvent is
 * skipped after a batch flush, so the next received message and the worker stop close it too.
 *
 * @internal
 */
final readonly class WorkerTraceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private IncomingTraceMiddleware $middleware,
    ) {
    }

    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            // Before any other listener logs in the previous message's trace.
            WorkerMessageReceivedEvent::class => ['closeUnit', 4096],
            WorkerRunningEvent::class => 'closeUnit',
            WorkerStoppedEvent::class => 'closeUnit',
        ];
    }

    public function closeUnit(): void
    {
        $this->middleware->closeUnit();
    }
}
