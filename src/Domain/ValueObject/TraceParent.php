<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Domain\ValueObject;

/**
 * W3C Trace Context `traceparent` value: https://www.w3.org/TR/trace-context/#traceparent-header.
 */
final readonly class TraceParent
{
    public const HEADER = 'traceparent';

    // W3C Trace Context Level 2: the server's span, returned to the caller.
    public const RESPONSE_HEADER = 'traceresponse';

    public const FLAG_SAMPLED = 0x01;

    public const FLAG_RANDOM = 0x02;

    // Version 00 defines only these; the spec has vendors zero the rest.
    private const KNOWN_FLAGS = self::FLAG_SAMPLED | self::FLAG_RANDOM;

    private const VERSION = '00';

    private const INVALID_TRACE_ID = '00000000000000000000000000000000';

    private const INVALID_SPAN_ID = '0000000000000000';

    // Versions above 00 may append fields, each introduced by "-".
    private const PATTERN = '/^(?<version>[0-9a-f]{2})-(?<traceId>[0-9a-f]{32})-(?<spanId>[0-9a-f]{16})-(?<flags>[0-9a-f]{2})(?<rest>-.*)?$/D';

    /**
     * @param non-empty-string $traceId 32 lowercase hex digits, not all zero
     * @param non-empty-string $spanId 16 lowercase hex digits, not all zero
     * @param int<0, 3> $flags
     */
    private function __construct(
        public string $traceId,
        public string $spanId,
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
            || $parts['spanId'] === self::INVALID_SPAN_ID
        ) {
            return null;
        }

        return new self($parts['traceId'], $parts['spanId'], ord(pack('H*', $parts['flags'])) & self::KNOWN_FLAGS);
    }

    /**
     * A W3C span id: 16 lowercase hex digits, not all zero.
     */
    public static function isSpanId(string $value): bool
    {
        return preg_match('/^[0-9a-f]{16}$/D', $value) === 1 && $value !== self::INVALID_SPAN_ID;
    }

    /**
     * A new trace: random 16-byte trace id and 8-byte span id, sampled when the ratio says so.
     */
    public static function start(?SampleRatio $ratio = null): self
    {
        $traceId = self::randomId(16, self::INVALID_TRACE_ID);
        $flags = !$ratio instanceof SampleRatio || $ratio->samples($traceId) ? self::FLAG_SAMPLED : 0;

        return new self($traceId, self::randomId(8, self::INVALID_SPAN_ID), $flags);
    }

    /**
     * The same trace and flags with a new span id.
     */
    public function child(): self
    {
        return new self($this->traceId, self::randomId(8, self::INVALID_SPAN_ID), $this->flags);
    }

    /**
     * Always version 00: a parsed higher version is propagated as the version this code knows.
     *
     * @return non-empty-string
     */
    public function toHeader(): string
    {
        return sprintf('%s-%s-%s-%02x', self::VERSION, $this->traceId, $this->spanId, $this->flags);
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
