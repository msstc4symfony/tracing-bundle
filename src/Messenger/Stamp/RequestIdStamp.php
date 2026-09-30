<?php

declare(strict_types=1);

namespace Hot\TracingBundle\Messenger\Stamp;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Messenger\Stamp\StampInterface;

#[Exclude]
final readonly class RequestIdStamp implements StampInterface
{
    public function __construct(
        public string $requestId,
        public string $requestFrom,
    ) {
    }
}
