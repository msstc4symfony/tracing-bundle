<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Sentry\Integration;

use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\W3cTraceContextInterface;
use Override;
use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Sentry\State\Scope;

/**
 * Opt-in: add the service id to `sentry.options.integrations`.
 *
 * The W3C trace goes into the `trace_id` / `span_id` tags, never into the `trace` context: that
 * one belongs to Sentry's own tracing, which links the event to its transactions.
 */
final readonly class TracingIntegration implements IntegrationInterface
{
    public function __construct(
        private RequestIdServiceInterface $storage,
        private ?W3cTraceContextInterface $w3cTraceContext = null,
    ) {
    }

    #[Override]
    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(
            static function (Event $event): Event {
                $integration = SentrySdk::getCurrentHub()->getIntegration(self::class);
                if ($integration instanceof self) {
                    $integration->addTraceTo($event);
                }

                return $event;
            },
        );
    }

    private function addTraceTo(Event $event): void
    {
        $event->setExtra($event->getExtra() + [
            'runtime_id' => $this->storage->getRuntimeId(),
            'request_id' => $this->storage->getRequestId(),
            'request_from' => $this->storage->getRequestFrom(),
        ]);

        $tags = $event->getTags();
        // An application-set trace_id or span_id means the pair is the application's: never mix halves.
        if (
            !$this->w3cTraceContext instanceof W3cTraceContextInterface
            || array_key_exists('trace_id', $tags)
            || array_key_exists('span_id', $tags)
        ) {
            return;
        }

        $traceParent = $this->w3cTraceContext->getTraceParent();
        $event->setTags([
            ...$tags,
            'trace_id' => $traceParent->traceId,
            'span_id' => $traceParent->parentId,
        ]);
    }
}
