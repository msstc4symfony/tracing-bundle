<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Framework\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Override;

/**
 * @internal
 */
final readonly class TraceLogProcessor implements ProcessorInterface
{
    public function __construct(
        private TraceContextHolderInterface $holder,
    ) {
    }

    #[Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $this->holder->current();

        return $record->with(extra: [
            ...$record->extra,
            'runtime_id' => $context->runtimeId,
            'trace_id' => $context->traceParent->traceId,
            'span_id' => $context->traceParent->spanId,
            'parent_span_id' => $context->parentSpanId,
            // Only the caller: the rest of the baggage may carry another service's private data.
            'caller' => $context->caller(),
        ]);
    }
}
