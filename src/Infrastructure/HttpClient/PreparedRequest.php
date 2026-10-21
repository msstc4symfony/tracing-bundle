<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Infrastructure\HttpClient;

/**
 * A request URL and options with the decorator's withOptions() defaults applied.
 *
 * @internal
 */
final readonly class PreparedRequest
{
    /**
     * @param array<mixed> $options
     */
    public function __construct(
        public string $url,
        public array $options,
    ) {
    }
}
