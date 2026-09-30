<?php

declare(strict_types=1);

namespace Hot\TracingBundle\EventListener;

use Hot\TracingBundle\Storage\RequestIdServiceInterface;
use Override;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

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
        $this->requestIdService
            ->initRuntimeId()
            ->generate()
        ;
    }
}
