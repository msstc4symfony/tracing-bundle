<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Storage\W3c;

use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vectors follow https://www.w3.org/TR/trace-context/#tracestate-header.
 */
#[CoversClass(TraceState::class)]
final class TraceStateTest extends TestCase
{
    public function testKeepsTheValueAsReceived(): void
    {
        self::assertSame('rojo=00f067aa0ba902b7,congo=t61rcWkgMzE', TraceState::fromHeaders(['rojo=00f067aa0ba902b7,congo=t61rcWkgMzE'])?->value);
    }

    public function testCombinesRepeatedHeadersInOrder(): void
    {
        self::assertSame('rojo=00f067aa0ba902b7,congo=t61rcWkgMzE', TraceState::fromHeaders(['rojo=00f067aa0ba902b7', 'congo=t61rcWkgMzE'])?->value);
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('rojo=1', TraceState::fromHeaders([" \trojo=1 \t"])?->value);
    }

    public function testBlankMembersAreDroppedAndDoNotCountTowardsTheLimit(): void
    {
        $value = implode(',', array_map(static fn (int $i): string => 'k' . $i . '=v', range(1, TraceState::MAX_MEMBERS)));

        self::assertSame($value, TraceState::fromHeaders([$value . ', ,'])?->value);
    }

    public function testSkipsBlankMembersInTheMiddle(): void
    {
        self::assertSame('rojo=1,congo=2', TraceState::fromHeaders(['rojo=1, ,congo=2'])?->value);
    }

    public function testAMemberOfExactly128CharactersIsNotLarge(): void
    {
        $edge = 'e=' . str_repeat('v', 126);
        $members = array_map(static fn (int $i): string => 'k' . $i . '=' . str_repeat('v', 97), range(1, 4));

        self::assertSame(implode(',', [$edge, ...array_slice($members, 0, 3)]), TraceState::fromHeaders([implode(',', [$edge, ...$members])])?->value);
    }

    public function testDropsWhitespaceAroundMembers(): void
    {
        self::assertSame('rojo=1,congo=2', TraceState::fromHeaders(["rojo=1 ,\tcongo=2"])?->value);
    }

    public function testAcceptsMultiTenantKeysAndValuesWithInnerSpaces(): void
    {
        self::assertSame('fw529a3039@dt=FzHEz/1 2,a_b-c*d/e=x', TraceState::fromHeaders(['fw529a3039@dt=FzHEz/1 2,a_b-c*d/e=x'])?->value);
    }

    public function testTruncatesLargeMembersFromTheEndFirst(): void
    {
        $a = 'a=' . str_repeat('v', 200);
        $b = 'b=' . str_repeat('v', 150);
        $c = 'c=' . str_repeat('v', 100);
        $d = 'd=' . str_repeat('v', 100);

        self::assertSame(implode(',', [$a, $c, $d]), TraceState::fromHeaders([implode(',', [$a, $b, $c, $d])])?->value);
    }

    public function testTruncatesFromTheEndWhenNoMemberIsLarge(): void
    {
        $members = array_map(static fn (int $i): string => 'k' . $i . '=' . str_repeat('v', 97), range(1, 6));

        self::assertSame(implode(',', array_slice($members, 0, 5)), TraceState::fromHeaders([implode(',', $members)])?->value);
    }

    public function testAcceptsTheMaximumLengthAndMemberCount(): void
    {
        $members = [];
        for ($i = 0; $i < TraceState::MAX_MEMBERS; $i++) {
            $members[] = sprintf('k%02d=%s', $i, str_repeat('v', 11));
        }

        $value = implode(',', $members);
        $value .= str_repeat('v', TraceState::MAX_LENGTH - strlen($value));
        self::assertSame(TraceState::MAX_LENGTH, strlen($value));

        self::assertSame($value, TraceState::fromHeaders([$value])?->value);
    }

    /**
     * @return iterable<string, array{list<string|null>}>
     */
    public static function provideIgnoredHeaders(): iterable
    {
        yield 'absent' => [[]];
        yield 'null' => [[null]];
        yield 'empty' => [['']];
        yield 'blank' => [[' , ']];
        yield 'value longer than 256 characters' => [['a=' . str_repeat('b', 257)]];
        yield 'member without =' => [['rojo=1,foo']];
        yield 'duplicate key' => [['rojo=1,congo=2,rojo=3']];
        yield 'upper-case key' => [['Rojo=1']];
        yield 'key starting with a digit' => [['1rojo=1']];
        yield 'empty value' => [['rojo=']];
        yield 'equals sign in value' => [['rojo=a=b']];
        yield 'more than 32 members' => [[implode(',', array_map(static fn (int $i): string => 'k' . $i . '=v', range(1, TraceState::MAX_MEMBERS + 1)))]];
        yield 'trailing line break' => [["rojo=1\n"]];
        yield 'control character' => [["rojo=00f067aa0ba902b7\r\nx: y"]];
        yield 'non-ascii' => [['rojo=é']];
    }

    /**
     * @param list<string|null> $headers
     */
    #[DataProvider('provideIgnoredHeaders')]
    public function testIgnoresUnusableHeaders(array $headers): void
    {
        self::assertNull(TraceState::fromHeaders($headers));
    }
}
