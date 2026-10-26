<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\SampleRatio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(SampleRatio::class)]
final class SampleRatioTest extends TestCase
{
    #[TestWith(['00000000000000000000000000000001'])]
    #[TestWith(['ffffffffffffffffffffffffffffffff'])]
    #[TestWith(['4bf92f3577b34da6a3ce929d0e0e4736'])]
    public function testOneSamplesEveryTrace(string $traceId): void
    {
        self::assertTrue(SampleRatio::always()->samples($traceId));
        self::assertTrue((new SampleRatio(1.0))->samples($traceId));
    }

    #[TestWith(['00000000000000000000000000000001'])]
    #[TestWith(['ffffffffffffffffffffffffffffffff'])]
    public function testZeroSamplesNoTrace(string $traceId): void
    {
        self::assertFalse((new SampleRatio(0.0))->samples($traceId));
    }

    public function testTheDecisionUsesTheLowest56BitsAgainstTheRejectionThreshold(): void
    {
        $half = new SampleRatio(0.5);

        // Threshold 2^55 = 0x80000000000000: the 14 lowest hex digits decide, the rest is ignored.
        self::assertTrue($half->samples('ffffffffffffffffff80000000000000'));
        self::assertFalse($half->samples('ffffffffffffffffff7fffffffffffff'));
        self::assertTrue($half->samples('0000000000000000008fffffffffffff'));
    }

    public function testTheThresholdMatchesOpenTelemetryRounding(): void
    {
        // OTEP 235: 2^56 - round(0.1 * 2^56) = 0xe6666666666666.
        $tenth = new SampleRatio(0.1);

        self::assertTrue($tenth->samples('000000000000000000e6666666666666'));
        self::assertFalse($tenth->samples('000000000000000000e6666666666665'));
    }

    public function testAHalfRoundsTheSampledShareUp(): void
    {
        // 7.5 / 2^56 * 2^56 = 7.5 rounds to 8: threshold 2^56 - 8.
        $tiny = new SampleRatio(7.5 / 2 ** 56);

        self::assertTrue($tiny->samples('000000000000000000fffffffffffff8'));
        self::assertFalse($tiny->samples('000000000000000000fffffffffffff7'));
    }

    /**
     * OTEP 235 `th`: the threshold in 14 hex digits without trailing zeros, "0" for zero.
     */
    #[TestWith([1.0, '0'])]
    #[TestWith([0.5, '8'])]
    #[TestWith([0.25, 'c'])]
    #[TestWith([0.1, 'e6666666666666'])]
    #[TestWith([1 - 2 ** -8, '01'])]
    #[TestWith([2 ** -56, 'ffffffffffffff'])]
    public function testTheThresholdInTraceStateForm(float $ratio, string $threshold): void
    {
        self::assertSame($threshold, (new SampleRatio($ratio))->threshold());
    }

    public function testZeroHasNoThreshold(): void
    {
        // 2^56 does not fit in 14 digits; such a ratio samples nothing, so no sampled trace carries it.
        self::assertNull((new SampleRatio(0.0))->threshold());
    }

    public function testTheShareOfSampledRandomTracesFollowsTheRatio(): void
    {
        $ratio = new SampleRatio(0.25);
        $sampled = 0;
        for ($i = 0; $i < 10000; $i++) {
            $sampled += $ratio->samples(bin2hex(random_bytes(16))) ? 1 : 0;
        }

        self::assertEqualsWithDelta(2500, $sampled, 200);
    }

    #[TestWith(['4bf92f3577b34da6a3ce929d0e0e47'])]
    #[TestWith(['4BF92F3577B34DA6A3CE929D0E0E4736'])]
    #[TestWith(['zzf92f3577b34da6a3ce929d0e0e4736'])]
    #[TestWith(["4bf92f3577b34da6a3ce929d0e0e4736\n"])]
    public function testRejectsAnInvalidTraceId(string $traceId): void
    {
        $this->expectException(InvalidArgumentException::class);

        SampleRatio::always()->samples($traceId);
    }

    #[TestWith([-0.01])]
    #[TestWith([1.01])]
    #[TestWith([NAN])]
    public function testRejectsARatioOutsideZeroToOne(float $ratio): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SampleRatio($ratio);
    }
}
