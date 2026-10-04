<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Storage\W3c;

/**
 * W3C Trace Context `tracestate` value. This bundle owns no entry in it, so members are
 * forwarded unchanged: https://www.w3.org/TR/trace-context/#tracestate-header.
 */
final readonly class TraceState
{
    // The spec asks vendors to propagate at least this many characters; above it members are
    // dropped, large ones first, then from the end (the leftmost member is the most recent).
    public const int MAX_LENGTH = 512;

    public const int MAX_MEMBERS = 32;

    private const int LARGE_MEMBER = 128;

    private const string MEMBER = '~^(?<key>[a-z][a-z0-9_\-*/]{0,255}|[a-z0-9][a-z0-9_\-*/]{0,240}@[a-z][a-z0-9_\-*/]{0,13})=[\x20-\x2b\x2d-\x3c\x3e-\x7e]{0,255}[\x21-\x2b\x2d-\x3c\x3e-\x7e]$~D';

    /**
     * @param non-empty-string $value
     */
    private function __construct(
        public string $value,
    ) {
    }

    /**
     * Repeated headers form one list, in the order received.
     *
     * @param list<string|null> $headers as HeaderBag::all() returns them
     *
     * @return self|null null when absent, empty, malformed, with a repeated key or too many members
     */
    public static function fromHeaders(array $headers): ?self
    {
        $members = [];
        foreach (explode(',', implode(',', $headers)) as $member) {
            $member = trim($member, " \t");
            if ($member === '') {
                continue;
            }

            if (preg_match(self::MEMBER, $member, $parts) !== 1 || array_key_exists($parts['key'], $members)) {
                return null;
            }

            $members[$parts['key']] = $member;
        }

        if (count($members) > self::MAX_MEMBERS) {
            return null;
        }

        $value = implode(',', self::truncate(array_values($members)));

        return $value === '' ? null : new self($value);
    }

    /**
     * @param list<non-empty-string> $members
     *
     * @return list<non-empty-string>
     */
    private static function truncate(array $members): array
    {
        while (strlen(implode(',', $members)) > self::MAX_LENGTH) {
            $large = array_keys(array_filter($members, static fn (string $member): bool => strlen($member) > self::LARGE_MEMBER));
            unset($members[$large === [] ? array_key_last($members) : $large[array_key_last($large)]]);
            $members = array_values($members);
        }

        return $members;
    }
}
