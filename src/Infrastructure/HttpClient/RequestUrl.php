<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Infrastructure\HttpClient;

use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\HttpClientTrait;

/**
 * An absolute request URL as the Symfony HttpClient transports read it — same parser, same base_uri resolution,
 * international hosts in punycode — so the host checked is the host the request goes to.
 *
 * @internal
 */
final class RequestUrl
{
    use HttpClientTrait;

    /**
     * @param string $origin scheme and authority ("https://user@host:8443")
     */
    private function __construct(
        public readonly string $url,
        public readonly string $origin,
        public readonly string $host,
    ) {
    }

    /**
     * @param string|array<mixed>|null $baseUri as the base_uri option: a URL, or parts already parsed
     *
     * @return self|null null when the transport would reject the URL or it has no host
     */
    public static function resolve(string $url, string|array|null $baseUri = null): ?self
    {
        try {
            $parts = self::resolveUrl(self::parseUrl($url), ClientOptions::base($baseUri));
        } catch (InvalidArgumentException) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $authority = $parts['authority'] ?? null;
        // A base_uri given as parts is the caller's, not parseUrl()'s: its authority may lack the "//".
        if (!is_string($scheme) || !is_string($authority) || !str_starts_with($authority, '//')) {
            return null;
        }

        // "//user:pass@host:port"; the user part is cut at its last "@", as the transports do.
        $at = strrpos($authority, '@');
        $hostAndPort = substr($authority, $at === false ? 2 : $at + 1);
        $host = preg_match('/^(?:\[[^]]*]|[^:]*)/', $hostAndPort, $match) === 1 ? $match[0] : '';

        $absolute = '';
        foreach ($parts as $part) {
            $absolute .= is_string($part) ? $part : '';
        }

        return new self($absolute, $scheme . $authority, $host);
    }
}
