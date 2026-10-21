<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Infrastructure\HttpClient;

use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\HttpClientTrait;
use Traversable;

/**
 * withOptions() defaults applied as a Symfony transport applies its own: mergeDefaultOptions() for the options, and
 * the default query added to a relative URL only, the URL's own query keys first.
 *
 * @internal
 */
final class ClientOptions
{
    use HttpClientTrait;

    /**
     * The defaults of a copy made by withOptions(): like a transport's, they forget an earlier default query.
     *
     * @param array<mixed> $options
     * @param array<mixed> $defaults
     *
     * @return array<mixed>
     */
    public static function defaults(array $options, array $defaults): array
    {
        $merged = self::mergeDefaultOptions(self::withHeaderArray($options), $defaults, true);
        unset($merged['normalized_headers']);

        return $merged;
    }

    /**
     * @param array<mixed> $options
     * @param array<mixed> $defaults
     */
    public static function prepare(string $url, array $options, array $defaults): PreparedRequest
    {
        if ($defaults === []) {
            return new PreparedRequest($url, $options);
        }

        $merged = self::defaults($options, $defaults);
        $defaultQuery = $defaults['query'] ?? [];
        if (!is_array($defaultQuery)) {
            // A transport fails on it; so does the inner client given it as the request's.
            $merged['query'] = $defaultQuery;

            return new PreparedRequest($url, $merged);
        }

        if ($defaultQuery === []) {
            return new PreparedRequest($url, $merged);
        }

        $query = $merged['query'] ?? [];
        if (!is_array($query)) {
            // The inner client reports it.
            return new PreparedRequest($url, $merged);
        }

        try {
            $parts = self::parseUrl($url, $query);
            $base = self::base($merged['base_uri'] ?? null);
            if ($base === null && $parts['scheme'] === null && $parts['authority'] === null) {
                // Relative to the inner client's own base_uri: it stays relative, with the default query merged in.
                $parts['query'] = self::defaultQueryIn($parts, $defaultQuery);
            } else {
                $parts = self::resolveUrl($parts, $base, $defaultQuery);
            }
        } catch (InvalidArgumentException) {
            // The inner client reports the invalid URL.
            return new PreparedRequest($url, $merged);
        }

        $resolved = '';
        foreach ($parts as $part) {
            $resolved .= is_string($part) ? $part : '';
        }

        $merged['query'] = [];

        return new PreparedRequest($resolved, $merged);
    }

    /**
     * A base_uri as resolveUrl() takes it: a string the transports parse, or parts already parsed.
     *
     * @return array<mixed>|null
     */
    public static function base(mixed $baseUri): ?array
    {
        if (is_string($baseUri)) {
            return self::parseUrl($baseUri);
        }

        return is_array($baseUri) ? $baseUri : null;
    }

    /**
     * @param array<mixed> $parts
     * @param array<mixed> $defaultQuery
     *
     * @return string|null "?..." as parseUrl() gives a query
     */
    private static function defaultQueryIn(array $parts, array $defaultQuery): ?string
    {
        $query = is_string($parts['query'] ?? null) ? substr($parts['query'], 1) : null;
        $merged = self::mergeQueryString($query, $defaultQuery, false);

        return $merged === null || $merged === '' ? null : '?' . $merged;
    }

    /**
     * @param array<mixed> $options
     *
     * @return array<mixed>
     */
    private static function withHeaderArray(array $options): array
    {
        if (($options['headers'] ?? null) instanceof Traversable) {
            $options['headers'] = iterator_to_array($options['headers']);
        }

        return $options;
    }
}
