<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration\Kernel;

use GuzzleHttp\ClientInterface;

final readonly class GuzzleConsumer
{
    public function __construct(
        public ClientInterface $client,
    ) {
    }
}
