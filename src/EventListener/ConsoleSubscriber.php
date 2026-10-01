<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\EventListener;

use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Override;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Every console command starts its own trace.
 */
final readonly class ConsoleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RequestIdServiceInterface $requestIdService,
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
        $this->requestIdService->reset();
        $this->requestIdService->generate();
    }
}
