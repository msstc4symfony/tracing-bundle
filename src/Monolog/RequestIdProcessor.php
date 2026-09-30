<?php

declare(strict_types=1);

namespace Hot\TracingBundle\Monolog;

use Hot\TracingBundle\Storage\RequestIdServiceInterface;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
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
        $record->extra['runtime_id'] = $this->storage->getRuntimeId();
        $record->extra['request_id'] = $this->storage->getRequestId();
        $record->extra['request_from'] = $this->storage->getRequestFrom();

        return $record;
    }
}
