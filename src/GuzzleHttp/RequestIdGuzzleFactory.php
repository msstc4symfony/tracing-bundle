<?php

declare(strict_types=1);

namespace Hot\TracingBundle\GuzzleHttp;

use GuzzleHttp\ClientInterface;

final readonly class RequestIdGuzzleFactory
{
    public function __construct(
        private RequestIdGuzzleHandler $handler,
    ) {
    }

    public function create(...$args): ClientInterface
    {
        $previousFactory = array_pop($args);
        $class = array_pop($args);

        /** @var ?ClientInterface $client */
        $client = null;
        if ($previousFactory && is_callable($previousFactory)) {
            $client = $previousFactory(...$args);
        }

        if (!$client) {
            $client = new $class(...$args);
        }

        $this->handler->addHandler($client);

        return $client;
    }
}
