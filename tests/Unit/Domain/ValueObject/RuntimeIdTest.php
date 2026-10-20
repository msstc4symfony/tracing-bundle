<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuntimeId::class)]
final class RuntimeIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bytesProvider(): iterable
    {
        yield 'all zero' => [str_repeat("\x00", 16), '00000000-0000-4000-8000-000000000000'];
        yield 'all one' => [str_repeat("\xff", 16), 'ffffffff-ffff-4fff-bfff-ffffffffffff'];
        yield 'counting' => [implode('', array_map(chr(...), range(0, 15))), '00010203-0405-4607-8809-0a0b0c0d0e0f'];
    }

    #[DataProvider('bytesProvider')]
    public function testSpellsSixteenBytesAsAVersion4Uuid(string $bytes, string $expected): void
    {
        self::assertSame($expected, RuntimeId::fromBytes($bytes));
    }

    public function testGeneratesADifferentVersion4UuidEachTime(): void
    {
        $first = RuntimeId::generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $first);
        self::assertNotSame($first, RuntimeId::generate());
    }

    public function testRejectsAnythingButSixteenBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RuntimeId::fromBytes(str_repeat("\x00", 15));
    }
}
