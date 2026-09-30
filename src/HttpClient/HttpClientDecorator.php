<?php

declare(strict_types=1);

namespace Hot\TracingBundle\HttpClient;

use Hot\TracingBundle\EventListener\HTTPRequestListener;
use Hot\TracingBundle\Storage\RequestIdServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

#[Exclude]
final readonly class HttpClientDecorator implements HttpClientInterface
{
    public function __construct(
        private HttpClientInterface $inner,
        private RequestIdServiceInterface $requestIdService,
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['headers'][HTTPRequestListener::REQUEST_ID_HEADER] = $this->requestIdService->getRequestId();
        $options['headers'][HTTPRequestListener::REQUEST_FROM_HEADER] = $this->requestIdService->getCurrentRequestFrom();

        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return $this->inner->withOptions($options);
    }
}
