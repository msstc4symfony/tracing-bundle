<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\W3cTraceContextInterface;
use Override;

final readonly class RequestIdProcessor implements ProcessorInterface
{
    public function __construct(
        private RequestIdServiceInterface $storage,
        private ?W3cTraceContextInterface $w3cTraceContext = null,
    ) {
    }

    #[Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = [
            ...$record->extra,
            'runtime_id' => $this->storage->getRuntimeId(),
            'request_id' => $this->storage->getRequestId(),
            'request_from' => $this->storage->getRequestFrom(),
        ];

        if ($this->w3cTraceContext instanceof W3cTraceContextInterface) {
            $traceParent = $this->w3cTraceContext->getTraceParent();
            $extra['trace_id'] = $traceParent->traceId;
            $extra['span_id'] = $traceParent->parentId;
        }

        return $record->with(extra: $extra);
    }
}
