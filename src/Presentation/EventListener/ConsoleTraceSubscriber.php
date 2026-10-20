<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Presentation\EventListener;

use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Override;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Every console command starts its own trace.
 *
 * @internal
 */
final readonly class ConsoleTraceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TraceContextHolderInterface $holder,
    ) {
    }

    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onCommand', 100],
        ];
    }

    public function onCommand(): void
    {
        $this->holder->reset();
    }
}
