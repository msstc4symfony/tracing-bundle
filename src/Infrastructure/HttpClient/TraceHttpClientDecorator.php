<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Infrastructure\HttpClient;

use Msstc4Symfony\TracingBundle\Application\BaggageRecipients;
use Msstc4Symfony\TracingBundle\Application\CallerName;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
use Override;
use Stringable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;
use Traversable;

/**
 * Adds the trace headers to every outgoing request unless the caller set them; baggage only for the hosts
 * BaggageRecipients allows.
 *
 * @internal
 */
#[Exclude]
final class TraceHttpClientDecorator implements HttpClientInterface, ResetInterface
{
    use DecoratorTrait {
        withOptions as private withInnerOptions;
    }

    /** The base_uri given to withOptions(): the inner client keeps it, request() never sees it. */
    private ?string $baseUri = null;

    public function __construct(
        HttpClientInterface $inner,
        private readonly TraceContextHolderInterface $holder,
        private readonly CallerName $caller,
        private readonly BaggageRecipients $recipients,
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

        $trace = $this->holder->current()->outgoing($this->caller->value);

        if ($this->entries($headers, TraceParent::HEADER) === []) {
            $headers[TraceParent::HEADER] = $trace->traceParent->toHeader();

            // tracestate belongs to the traceparent it came with; a caller-made traceparent keeps its own.
            if ($trace->traceState instanceof TraceState && $this->entries($headers, TraceState::HEADER) === []) {
                $headers[TraceState::HEADER] = $trace->traceState->value;
            }
        }

        if ($this->recipients->allowsEveryHost() || $this->recipients->allows($this->hostOf($url, $options))) {
            $headers = $this->withBaggage($headers, $trace->baggage);
        }

        $options['headers'] = $headers;

        return $this->client->request($method, $url, $options);
    }

    /**
     * @param array<mixed> $options
     */
    #[Override]
    public function withOptions(array $options): static
    {
        $clone = $this->withInnerOptions($options);
        if (is_string($options['base_uri'] ?? null)) {
            $clone->baseUri = $options['base_uri'];
        }

        return $clone;
    }

    /**
     * A relative URL reaches the transport with the base_uri the transport resolves it against.
     *
     * @param array<mixed> $options
     */
    private function hostOf(string $url, array $options): string
    {
        $baseUri = is_string($options['base_uri'] ?? null) ? $options['base_uri'] : $this->baseUri;
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) && $baseUri !== null) {
            $host = parse_url($baseUri, PHP_URL_HOST);
        }

        return is_string($host) ? $host : '';
    }

    /**
     * The caller's own baggage is sent as given, followed by the members it does not name — this service as
     * caller and the received ones — so a client-level writer of baggage (Sentry) does not cut the chain.
     *
     * @param array<mixed> $headers
     *
     * @return array<mixed>
     */
    private function withBaggage(array $headers, Baggage $baggage): array
    {
        $given = $this->entries($headers, Baggage::HEADER);
        if ($given === []) {
            $header = $baggage->toHeader();
            if ($header !== null) {
                $headers[Baggage::HEADER] = $header;
            }

            return $headers;
        }

        $values = array_merge(...array_values($given));
        $missing = $baggage->except(Baggage::fromHeaders($values))->toHeader();
        if ($missing === null) {
            return $headers;
        }

        // One header: HttpClient keeps only the last of repeated header lines.
        foreach (array_keys($given) as $key) {
            unset($headers[$key]);
        }
        $headers[Baggage::HEADER] = implode(', ', [...$values, $missing]);

        return $headers;
    }

    /**
     * The entries named $name, as HttpClient reads them: name => value(s), or a "Name: value" line.
     *
     * @param array<mixed> $headers
     *
     * @return array<array-key, list<string>> values by their key in $headers
     */
    private function entries(array $headers, string $name): array
    {
        $entries = [];
        foreach ($headers as $key => $value) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                $entries[$key] = $this->strings(is_array($value) ? $value : [$value]);
            } elseif (!is_string($key) && is_string($value) && stripos($value, $name . ':') === 0) {
                $entries[$key] = [ltrim(substr($value, strlen($name) + 1))];
            }
        }

        return $entries;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (is_string($value) || $value instanceof Stringable) {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }
}
