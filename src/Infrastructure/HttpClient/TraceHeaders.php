<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Infrastructure\HttpClient;

use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\OutgoingTrace;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
use Stringable;
use Traversable;

/**
 * Trace headers of a Symfony HttpClient request, in any of the forms HttpClient accepts: name => value(s), or
 * "Name: value" lines. Headers the caller set are never replaced.
 *
 * @internal
 */
final readonly class TraceHeaders
{
    /**
     * @param array<mixed> $options
     *
     * @return array<mixed>|null null when they are not headers; the inner client reports that
     */
    public function of(array $options): ?array
    {
        $headers = $options['headers'] ?? [];
        if ($headers instanceof Traversable) {
            $headers = iterator_to_array($headers);
        }

        return is_array($headers) ? $headers : null;
    }

    /**
     * @param array<mixed> $headers
     *
     * @return array<mixed>
     */
    public function with(array $headers, OutgoingTrace $trace, bool $withBaggage): array
    {
        if ($this->entries($headers, TraceParent::HEADER) === []) {
            $headers[TraceParent::HEADER] = $trace->traceParent->toHeader();

            // tracestate belongs to the traceparent it came with; a caller-made traceparent keeps its own.
            if ($trace->traceState instanceof TraceState && $this->entries($headers, TraceState::HEADER) === []) {
                $headers[TraceState::HEADER] = $trace->traceState->value;
            }
        }

        return $withBaggage ? $this->withBaggage($headers, $trace->baggage) : $headers;
    }

    /**
     * @param array<mixed> $headers
     *
     * @return array<mixed>
     */
    public function without(array $headers, string ...$names): array
    {
        foreach ($names as $name) {
            foreach (array_keys($this->entries($headers, $name)) as $key) {
                unset($headers[$key]);
            }
        }

        return $headers;
    }

    /**
     * Removes the headers and keeps the inner client from adding its defaults for them: HttpClient adds a default
     * header only when the request has no entry of that name, and an empty list sends none.
     *
     * @param array<mixed> $headers
     *
     * @return array<mixed>
     */
    public function blocking(array $headers, string ...$names): array
    {
        $headers = $this->without($headers, ...$names);
        foreach ($names as $name) {
            $headers[$name] = [];
        }

        return $headers;
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
        $headers = $this->without($headers, Baggage::HEADER);
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
