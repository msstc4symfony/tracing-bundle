<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\GuzzleHttp\Fixture;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use LogicException;
use Override;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A Guzzle 8 client: the interface no longer has getConfig(). Loaded only on Guzzle 8.
 */
final class ClientWithoutConfig implements ClientInterface
{
    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        throw new LogicException('Not used');
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        throw new LogicException('Not used');
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    public function request(string $method, $uri, array $options = []): ResponseInterface
    {
        throw new LogicException('Not used');
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    public function requestAsync(string $method, $uri, array $options = []): PromiseInterface
    {
        throw new LogicException('Not used');
    }
}
