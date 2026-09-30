<?php

declare(strict_types=1);

namespace Hot\TracingBundle\GuzzleHttp;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RequestIdGuzzleDecorator implements ClientInterface
{
    public function __construct(
        private ClientInterface $client,
        RequestIdGuzzleHandler $handler,
    ) {
        $handler->addHandler($client);
    }

    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        return $this->client->send($request, $options);
    }

    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        return $this->client->sendAsync($request, $options);
    }

    public function request(string $method, $uri, array $options = []): ResponseInterface
    {
        return $this->client->request($method, $uri, $options);
    }

    public function requestAsync(string $method, $uri, array $options = []): PromiseInterface
    {
        return $this->client->requestAsync($method, $uri, $options);
    }

    public function getConfig(?string $option = null)
    {
        return $this->client->getConfig($option);
    }

    public function __call(string $name, array $arguments)
    {
        return $this->client->$name(...$arguments);
    }
}
