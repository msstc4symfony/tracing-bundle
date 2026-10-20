<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Framework\Sentry;

use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
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
        private TraceContextHolderInterface $holder,
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
        $context = $this->holder->current();
        $event->setExtra($event->getExtra() + [
            'runtime_id' => $context->runtimeId,
            'caller' => $context->caller(),
        ]);

        $tags = $event->getTags();
        // An application-set trace_id or span_id means the pair is the application's: never mix halves.
        if (array_key_exists('trace_id', $tags) || array_key_exists('span_id', $tags)) {
            return;
        }

        $event->setTags([
            ...$tags,
            'trace_id' => $context->traceParent->traceId,
            'span_id' => $context->traceParent->spanId,
        ]);
    }
}
