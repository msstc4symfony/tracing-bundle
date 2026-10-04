<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration\Kernel;

use GuzzleHttp\ClientInterface;

/**
 * Stands for a configurator the application set on its own Guzzle client.
 */
final class AppGuzzleConfigurator
{
    /** @var list<ClientInterface> */
    public array $configured = [];

    public function configure(ClientInterface $client): void
    {
        $this->configured[] = $client;
    }
}
