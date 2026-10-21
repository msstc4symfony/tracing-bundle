<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Application;

use InvalidArgumentException;

/**
 * The hosts outgoing requests send W3C baggage to: every host, or only the listed ones.
 *
 * An entry is a host name, an IP address (IPv6 in brackets) or `*.example.com` for any subdomain of example.com.
 */
final readonly class BaggageRecipients
{
    private const NAME = '/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/';

    // A top-level domain is never numeric, so "*.0.1" would only ever match IPv4 addresses.
    private const NUMERIC_TOP_LABEL = '/(?:^|\.)[0-9]+$/';

    private const WILDCARD = '*.';

    /** @var array<string, true>|null null: every host */
    private ?array $hosts;

    /** @var list<string> ".example.com" for "*.example.com" */
    private array $domains;

    /**
     * @param list<string|null>|null $allowedHosts null: every host; a null entry is an unset %env()%
     *
     * @throws InvalidArgumentException when an entry is not a host, an IP address or a `*.` wildcard
     */
    public function __construct(?array $allowedHosts = null)
    {
        $hosts = null;
        $domains = [];

        if ($allowedHosts !== null) {
            $hosts = [];
            foreach ($allowedHosts as $entry) {
                if ($entry === null) {
                    throw $this->invalid(null);
                }

                $host = $this->withoutRootDot(strtolower($entry));

                if (str_starts_with($host, self::WILDCARD)) {
                    $domain = substr($host, strlen(self::WILDCARD));
                    if (!$this->isDomain($domain)) {
                        throw $this->invalid($entry);
                    }

                    $domains[] = '.' . $domain;

                    continue;
                }

                $hosts[$this->normalize($host) ?? throw $this->invalid($entry)] = true;
            }
        }

        $this->hosts = $hosts;
        $this->domains = $domains;
    }

    public function allowsEveryHost(): bool
    {
        return $this->hosts === null;
    }

    public function allows(string $host): bool
    {
        if ($this->hosts === null) {
            return true;
        }

        // normalize() never returns "", which no entry or domain matches.
        $host = $this->normalize($this->withoutRootDot(strtolower($host))) ?? '';
        if (isset($this->hosts[$host])) {
            return true;
        }

        foreach ($this->domains as $domain) {
            if (str_ends_with($host, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "orders.example.com." is the fully qualified spelling of "orders.example.com"; one dot only.
     */
    private function withoutRootDot(string $host): string
    {
        return str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
    }

    private function isDomain(string $name): bool
    {
        return preg_match(self::NAME, $name) === 1 && preg_match(self::NUMERIC_TOP_LABEL, $name) !== 1;
    }

    /**
     * A lower-case host name or IPv4 address as is, an IPv6 address (given in brackets) in its shortest form
     * without them, null for neither.
     */
    private function normalize(string $host): ?string
    {
        if (preg_match(self::NAME, $host) === 1) {
            return $host;
        }

        if (!str_starts_with($host, '[') || !str_ends_with($host, ']')) {
            return null;
        }

        $address = filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        $packed = is_string($address) ? inet_pton($address) : false;
        $shortest = $packed === false ? false : inet_ntop($packed);

        return $shortest === false ? null : $shortest;
    }

    private function invalid(?string $entry): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('%s is not a host, an IP address or "*.domain".', $entry === null ? 'null' : '"' . $entry . '"'));
    }
}
