<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\DependencyInjection\Fixture;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use LogicException;
use Override;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An application client whose first constructor argument is not Guzzle's config.
 */
final readonly class CustomConstructorClient implements ClientInterface
{
    public function __construct(
        public string $token,
    ) {
    }

    public function getConfig(?string $option = null): null
    {
        return null;
    }

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
