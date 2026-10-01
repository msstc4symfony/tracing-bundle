<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Sentry\Integration;

use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Override;
use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Sentry\State\Scope;

/**
 * Opt-in: add the service id to `sentry.options.integrations`.
 */
final readonly class TracingIntegration implements IntegrationInterface
{
    public function __construct(
        private RequestIdServiceInterface $storage,
    ) {
    }

    #[Override]
    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(
            static function (Event $event): Event {
                $integration = SentrySdk::getCurrentHub()->getIntegration(self::class);
                if ($integration instanceof self) {
                    $event->setExtra($event->getExtra() + $integration->extra());
                }

                return $event;
            },
        );
    }

    /**
     * @return array{runtime_id: string, request_id: string, request_from: string}
     */
    private function extra(): array
    {
        return [
            'runtime_id' => $this->storage->getRuntimeId(),
            'request_id' => $this->storage->getRequestId(),
            'request_from' => $this->storage->getRequestFrom(),
        ];
    }
}
