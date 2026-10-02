<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Storage\W3c;

/**
 * W3C Trace Context `traceparent` value: https://www.w3.org/TR/trace-context/#traceparent-header.
 */
final readonly class TraceParent
{
    public const int FLAG_SAMPLED = 0x01;

    public const int FLAG_RANDOM = 0x02;

    // Version 00 defines only these; the spec has vendors zero the rest.
    private const int KNOWN_FLAGS = self::FLAG_SAMPLED | self::FLAG_RANDOM;

    private const string VERSION = '00';

    private const string INVALID_TRACE_ID = '00000000000000000000000000000000';

    private const string INVALID_PARENT_ID = '0000000000000000';

    // Versions above 00 may append fields, each introduced by "-".
    private const string PATTERN = '/^(?<version>[0-9a-f]{2})-(?<traceId>[0-9a-f]{32})-(?<parentId>[0-9a-f]{16})-(?<flags>[0-9a-f]{2})(?<rest>-.*)?$/D';

    /**
     * @param non-empty-string $traceId 32 lowercase hex digits, not all zero
     * @param non-empty-string $parentId 16 lowercase hex digits, not all zero
     * @param int<0, 3> $flags
     */
    private function __construct(
        public string $traceId,
        public string $parentId,
        public int $flags,
    ) {
    }

    /**
     * @return self|null null for a value the spec says to ignore
     */
    public static function fromHeader(string $header): ?self
    {
        if (preg_match(self::PATTERN, trim($header, " \t"), $parts) !== 1) {
            return null;
        }

        $tail = $parts['rest'] ?? '';
        if (
            $parts['version'] === 'ff'
            || ($parts['version'] === self::VERSION && $tail !== '')
            || $parts['traceId'] === self::INVALID_TRACE_ID
            || $parts['parentId'] === self::INVALID_PARENT_ID
        ) {
            return null;
        }

        return new self($parts['traceId'], $parts['parentId'], ord(pack('H*', $parts['flags'])) & self::KNOWN_FLAGS);
    }

    /**
     * A new sampled trace: random 16-byte trace id and 8-byte span id.
     */
    public static function start(): self
    {
        return new self(self::randomId(16, self::INVALID_TRACE_ID), self::randomId(8, self::INVALID_PARENT_ID), self::FLAG_SAMPLED);
    }

    /**
     * The same trace and flags with a new span id.
     */
    public function child(): self
    {
        return new self($this->traceId, self::randomId(8, self::INVALID_PARENT_ID), $this->flags);
    }

    /**
     * Always version 00: a parsed higher version is propagated as the version this code knows.
     *
     * @return non-empty-string
     */
    public function toHeader(): string
    {
        return sprintf('%s-%s-%s-%02x', self::VERSION, $this->traceId, $this->parentId, $this->flags);
    }

    /**
     * The trace id in the 8-4-4-4-12 UUID layout, so a request id derived from it keeps the
     * shape request ids have elsewhere. Its version/variant bits are whatever the trace id holds.
     *
     * @return non-empty-string
     */
    public function toRequestId(): string
    {
        return implode('-', [
            substr($this->traceId, 0, 8),
            substr($this->traceId, 8, 4),
            substr($this->traceId, 12, 4),
            substr($this->traceId, 16, 4),
            substr($this->traceId, 20),
        ]);
    }

    /**
     * @param positive-int $bytes
     * @param non-empty-string $invalid
     *
     * @return non-empty-string
     */
    private static function randomId(int $bytes, string $invalid): string
    {
        do {
            $id = bin2hex(random_bytes($bytes));
        } while ($id === $invalid || $id === '');

        return $id;
    }
}
