<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\GuzzleHttp;

use Closure;
use GuzzleHttp\ClientInterface;

/**
 * Runs a client's own service configurator, then adds the tracing middleware: a service has
 * a single configurator slot, which `GuzzlePass` must not take away from the application.
 *
 * @internal
 */
final readonly class ChainedClientConfigurator
{
    /** @var Closure(ClientInterface): mixed */
    private Closure $configurator;

    /**
     * @param callable(ClientInterface): mixed $configurator
     */
    public function __construct(
        callable $configurator,
        private RequestIdGuzzleHandler $tracing,
    ) {
        $this->configurator = $configurator(...);
    }

    public function __invoke(ClientInterface $client): void
    {
        ($this->configurator)($client);
        $this->tracing->addHandler($client);
    }
}
