<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Infrastructure\HttpClient;

use Msstc4Symfony\TracingBundle\Application\CallerName;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Adds the trace headers, baggage included, to every outgoing request unless the caller set them. Wired when
 * every host may receive baggage; AllowListTraceHttpClientDecorator otherwise.
 *
 * @internal
 */
#[Exclude]
final class TraceHttpClientDecorator implements HttpClientInterface, ResetInterface
{
    use DecoratorTrait;

    private readonly TraceHeaders $headers;

    public function __construct(
        HttpClientInterface $inner,
        private readonly TraceContextHolderInterface $holder,
        private readonly CallerName $caller,
    ) {
        $this->client = $inner;
        $this->headers = new TraceHeaders();
    }

    /**
     * @param array<mixed> $options
     */
    #[Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $headers = $this->headers->of($options);
        if ($headers !== null) {
            $options['headers'] = $this->headers->with($headers, $this->holder->current()->outgoing($this->caller->value), true);
        }

        return $this->client->request($method, $url, $options);
    }
}
