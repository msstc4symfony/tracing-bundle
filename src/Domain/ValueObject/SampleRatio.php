<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Share of new traces marked sampled, decided from the trace id alone (W3C Trace Context Level 2 / OpenTelemetry
 * TraceIdRatioBased): services with the same ratio make the same decision for a trace.
 */
final readonly class SampleRatio
{
    // The 56 lowest bits of the trace id: 14 hex digits.
    private const RANDOM_HEX_DIGITS = 14;

    private const RANDOM_VALUES = 2 ** 56;

    /** @var int<0, 72057594037927936> trace ids whose random part is below it are not sampled */
    private int $threshold;

    public function __construct(
        public float $ratio,
    ) {
        if (is_nan($ratio) || $ratio < 0.0 || $ratio > 1.0) {
            throw new InvalidArgumentException(sprintf('The sample ratio must be between 0 and 1, %F given.', $ratio));
        }

        $this->threshold = max(0, min(self::RANDOM_VALUES, (int) ceil((1.0 - $ratio) * self::RANDOM_VALUES)));
    }

    public static function always(): self
    {
        return new self(1.0);
    }

    public function samples(string $traceId): bool
    {
        return hexdec(substr($traceId, -self::RANDOM_HEX_DIGITS)) >= $this->threshold;
    }
}
