<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Override;

final readonly class RequestIdProcessor implements ProcessorInterface
{
    public function __construct(
        private RequestIdServiceInterface $storage,
    ) {
    }

    #[Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [
            ...$record->extra,
            'runtime_id' => $this->storage->getRuntimeId(),
            'request_id' => $this->storage->getRequestId(),
            'request_from' => $this->storage->getRequestFrom(),
        ]);
    }
}
