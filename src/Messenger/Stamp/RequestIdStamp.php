<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class RequestIdStamp implements StampInterface
{
    public function __construct(
        public string $requestId,
        public string $requestFrom,
    ) {
    }
}
