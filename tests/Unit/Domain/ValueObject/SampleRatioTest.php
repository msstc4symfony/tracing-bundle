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

    public function testTheShareOfSampledRandomTracesFollowsTheRatio(): void
    {
        $ratio = new SampleRatio(0.25);
        $sampled = 0;
        for ($i = 0; $i < 10000; $i++) {
            $sampled += $ratio->samples(bin2hex(random_bytes(16))) ? 1 : 0;
        }

        self::assertEqualsWithDelta(2500, $sampled, 200);
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
