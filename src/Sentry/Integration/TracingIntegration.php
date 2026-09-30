<?php

declare(strict_types=1);

namespace Hot\TracingBundle\Sentry\Integration;

use Hot\TracingBundle\Storage\RequestIdServiceInterface;
use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Sentry\State\Scope;

final readonly class TracingIntegration implements IntegrationInterface
{
    public function __construct(
        private RequestIdServiceInterface $storage,
    ) {
    }

    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(
            static function (Event $event): Event {
                $integration = SentrySdk::getCurrentHub()->getIntegration(self::class);

                if ($integration instanceof IntegrationInterface) {
                    $event->setExtra(
                        $event->getExtra()
                        + [
                            'runtime_id' => $integration->storage->getRuntimeId(),
                            'request_id' => $integration->storage->getRequestId(),
                            'request_from' => $integration->storage->getRequestFrom(),
                        ],
                    );
                }

                return $event;
            },
        );
    }
}
