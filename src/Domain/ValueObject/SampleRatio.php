<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Share of new traces marked sampled, decided from the trace id alone (W3C Trace Context Level 2 / OpenTelemetry
 * probability sampling, OTEP 235): services with the same ratio make the same decision for a trace.
 */
final readonly class SampleRatio
{
    // The 56 lowest bits of the trace id: 14 hex digits.
    private const RANDOM_HEX_DIGITS = 14;

    private const TRACE_ID = '/^[0-9a-f]{32}$/D';

    private const RANDOM_VALUES = 2 ** 56;

    // Trace ids whose random part is below it are not sampled.
    private int $threshold;

    public function __construct(
        public float $ratio,
    ) {
        if (is_nan($ratio) || $ratio < 0.0 || $ratio > 1.0) {
            throw new InvalidArgumentException(sprintf('The sample ratio must be between 0 and 1, %F given.', $ratio));
        }

        // OTEP 235: 2^56 - round(ratio * 2^56). The product is exact; PHP's round() is off by one for even whole floats
        // in 2^52..2^53 (round(7205759403792794.0) = 7205759403792795.0).
        $scaled = $ratio * self::RANDOM_VALUES;
        $whole = (int) $scaled;
        $this->threshold = self::RANDOM_VALUES - ($scaled - $whole >= 0.5 ? $whole + 1 : $whole);
    }

    /**
     * The OTEP 235 `th` value: the threshold in 14 hex digits without trailing zeros, "0" for zero.
     *
     * @return non-empty-string|null null for ratio 0, whose threshold (2^56) has no such form
     */
    public function threshold(): ?string
    {
        if ($this->threshold === self::RANDOM_VALUES) {
            return null;
        }

        $digits = rtrim(sprintf('%0' . self::RANDOM_HEX_DIGITS . 'x', $this->threshold), '0');

        return $digits === '' ? '0' : $digits;
    }

    public static function always(): self
    {
        return new self(1.0);
    }

    /**
     * @param string $traceId 32 lowercase hex digits
     *
     * @throws InvalidArgumentException for anything else
     */
    public function samples(string $traceId): bool
    {
        if (preg_match(self::TRACE_ID, $traceId) !== 1) {
            throw new InvalidArgumentException(sprintf('A trace id is 32 lowercase hex digits, "%s" given.', $traceId));
        }

        return hexdec(substr($traceId, -self::RANDOM_HEX_DIGITS)) >= $this->threshold;
    }
}
