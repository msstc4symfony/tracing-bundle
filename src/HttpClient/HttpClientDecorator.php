<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\HttpClient;

use Msstc4Symfony\TracingBundle\EventListener\HTTPRequestListener;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;
use Traversable;

/**
 * Adds the trace headers to every outgoing request unless the caller set them.
 */
#[Exclude]
final class HttpClientDecorator implements HttpClientInterface, ResetInterface
{
    use DecoratorTrait;

    public function __construct(
        HttpClientInterface $inner,
        private readonly RequestIdServiceInterface $requestIdService,
    ) {
        $this->client = $inner;
    }

    /**
     * @param array<mixed> $options
     */
    #[Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $headers = $options['headers'] ?? [];
        if ($headers instanceof Traversable) {
            $headers = iterator_to_array($headers);
        }

        // Anything else is invalid input for the inner client; let it report it.
        if (!is_array($headers)) {
            return $this->client->request($method, $url, $options);
        }

        if (!$this->hasHeader($headers, HTTPRequestListener::REQUEST_ID_HEADER)) {
            $headers[HTTPRequestListener::REQUEST_ID_HEADER] = $this->requestIdService->getRequestId();
        }

        if (!$this->hasHeader($headers, HTTPRequestListener::REQUEST_FROM_HEADER)) {
            $headers[HTTPRequestListener::REQUEST_FROM_HEADER] = $this->requestIdService->getCurrentRequestFrom();
        }

        if (!$this->hasHeader($headers, HTTPRequestListener::TRACEPARENT_HEADER)) {
            $headers[HTTPRequestListener::TRACEPARENT_HEADER] = $this->requestIdService->createOutgoingTraceParent()->toHeader();

            // tracestate belongs to the traceparent it came with; a caller-made traceparent keeps its own.
            $state = $this->requestIdService->getTraceState();
            if ($state instanceof TraceState && !$this->hasHeader($headers, HTTPRequestListener::TRACESTATE_HEADER)) {
                $headers[HTTPRequestListener::TRACESTATE_HEADER] = $state->value;
            }
        }

        $options['headers'] = $headers;

        return $this->client->request($method, $url, $options);
    }

    /**
     * Headers come either as name => value or as a list of "Name: value" lines.
     *
     * @param array<mixed> $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $key => $value) {
            $header = is_string($key) ? $key : (is_string($value) ? strstr($value, ':', true) : false);
            if (is_string($header) && strcasecmp(trim($header), $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
