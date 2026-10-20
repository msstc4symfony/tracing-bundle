<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Domain\ValueObject;

use InvalidArgumentException;

/**
 * W3C Baggage: https://www.w3.org/TR/baggage/. Members are kept as received and forwarded, so a
 * service that does not read them never breaks a chain of services that do.
 */
final readonly class Baggage
{
    public const HEADER = 'baggage';

    public const MAX_MEMBERS = 180;

    public const MAX_LENGTH = 8192;

    private const TOKEN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D';

    // baggage-octet: printable US-ASCII except DQUOTE, comma, semicolon and backslash.
    private const VALUE = '/^[\x21\x23-\x2b\x2d-\x3a\x3c-\x5b\x5d-\x7e]*$/D';

    /**
     * @param array<non-empty-string, array{value: string, properties: string}> $members decoded value and the
     *                                                                                   raw properties after the first ";"
     */
    private function __construct(
        private array $members,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Repeated headers form one list, in the order received. Invalid members are dropped; above the limits
     * members are dropped from the end.
     *
     * @param array<array-key, string|null> $headers as HeaderBag::all() or PSR-7 getHeader() returns them
     */
    public static function fromHeaders(array $headers): self
    {
        $members = [];
        foreach (explode(',', implode(',', $headers)) as $raw) {
            $member = self::parse($raw);
            if ($member !== null) {
                $members[$member[0]] = ['value' => $member[1], 'properties' => $member[2]];
            }
        }

        return new self(self::truncate($members));
    }

    public function get(string $key): ?string
    {
        return $this->members[$key]['value'] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->members);
    }

    /**
     * A new member goes first: truncation drops members from the end, so the one just added survives.
     *
     * @throws InvalidArgumentException when $key is not a token or $value is not UTF-8
     */
    public function with(string $key, string $value): self
    {
        if ($key === '' || preg_match(self::TOKEN, $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Baggage key "%s" is not a token.', $key));
        }

        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Baggage value of "%s" is not UTF-8.', $key));
        }

        $member = ['value' => $value, 'properties' => ''];
        if ($this->has($key)) {
            $members = $this->members;
            $members[$key] = $member;

            return new self($members);
        }

        return new self(self::truncate([$key => $member] + $this->members));
    }

    /**
     * The members whose key $other does not name.
     */
    public function except(self $other): self
    {
        return new self(array_diff_key($this->members, $other->members));
    }

    public function isEmpty(): bool
    {
        return $this->members === [];
    }

    /**
     * @return non-empty-string|null
     */
    public function toHeader(): ?string
    {
        $header = self::join($this->members);

        return $header === '' ? null : $header;
    }

    /**
     * @return array{non-empty-string, string, string}|null key, decoded value and raw properties; null when invalid
     */
    private static function parse(string $raw): ?array
    {
        $parts = explode(';', $raw);
        $pair = explode('=', array_shift($parts), 2);
        if (count($pair) !== 2) {
            return null;
        }

        $key = trim($pair[0], " \t");
        $value = trim($pair[1], " \t");
        if ($key === '' || preg_match(self::TOKEN, $key) !== 1 || preg_match(self::VALUE, $value) !== 1) {
            return null;
        }

        $decoded = rawurldecode($value);
        if (preg_match('//u', $decoded) !== 1) {
            return null;
        }

        $properties = [];
        foreach ($parts as $property) {
            $property = trim($property, " \t");
            if ($property === '') {
                continue;
            }

            $pair = array_map(static fn (string $part): string => trim($part, " \t"), explode('=', $property, 2));
            if (preg_match(self::TOKEN, $pair[0]) !== 1 || (isset($pair[1]) && preg_match(self::VALUE, $pair[1]) !== 1)) {
                return null;
            }

            $properties[] = implode('=', $pair);
        }

        return [$key, $decoded, implode(';', $properties)];
    }

    /**
     * @param array<non-empty-string, array{value: string, properties: string}> $members
     *
     * @return array<non-empty-string, array{value: string, properties: string}>
     */
    private static function truncate(array $members): array
    {
        $members = array_slice($members, 0, self::MAX_MEMBERS, true);
        while (strlen(self::join($members)) > self::MAX_LENGTH) {
            array_pop($members);
        }

        return $members;
    }

    /**
     * @param array<non-empty-string, array{value: string, properties: string}> $members
     */
    private static function join(array $members): string
    {
        $encoded = [];
        foreach ($members as $key => $member) {
            $encoded[] = $key . '=' . rawurlencode($member['value']) . ($member['properties'] === '' ? '' : ';' . $member['properties']);
        }

        return implode(',', $encoded);
    }
}
