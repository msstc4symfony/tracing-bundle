<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Baggage::class)]
final class BaggageTest extends TestCase
{
    public function testParsesMembersAcrossRepeatedHeadersWithOptionalWhitespace(): void
    {
        $baggage = Baggage::fromHeaders(['userId=alice , serverNode = DF%2028', 'isProduction=false', null]);

        self::assertSame('alice', $baggage->get('userId'));
        self::assertSame('DF 28', $baggage->get('serverNode'));
        self::assertSame('false', $baggage->get('isProduction'));
        self::assertNull($baggage->get('missing'));
        self::assertSame('userId=alice,serverNode=DF%2028,isProduction=false', $baggage->toHeader());
    }

    public function testRoundTripsPercentEncodedUtf8AndProperties(): void
    {
        $baggage = Baggage::fromHeaders(['city=M%C3%BCnchen;ttl=3600;secret, flag=1']);

        self::assertSame('München', $baggage->get('city'));
        self::assertSame('city=M%C3%BCnchen;ttl=3600;secret,flag=1', $baggage->toHeader());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMemberProvider(): iterable
    {
        yield 'no equals sign' => ['novalue'];
        yield 'empty key' => ['=value'];
        yield 'key with a space' => ['my key=value'];
        yield 'value with a double quote' => ['key="quoted"'];
        yield 'value with a backslash' => ['key=a\\b'];
        yield 'property with an invalid key' => ['key=value;bad key=1'];
    }

    #[DataProvider('invalidMemberProvider')]
    public function testDropsAnInvalidMemberAndKeepsTheRest(string $member): void
    {
        $baggage = Baggage::fromHeaders(['first=1,' . $member . ',last=2']);

        self::assertSame('first=1,last=2', $baggage->toHeader());
    }

    public function testDropsAMemberWhoseValueIsNotUtf8(): void
    {
        self::assertSame('ok=1', Baggage::fromHeaders(['bad=%FF%FE,ok=1'])->toHeader());
    }

    public function testARepeatedKeyKeepsItsFirstPositionAndTheLastValue(): void
    {
        self::assertSame('a=3,b=2', Baggage::fromHeaders(['a=1,b=2', 'a=3'])->toHeader());
    }

    public function testDropsMembersFromTheEndAboveTheLimits(): void
    {
        $many = implode(',', array_map(static fn (int $i): string => 'k' . $i . '=v', range(1, Baggage::MAX_MEMBERS + 5)));
        $byCount = Baggage::fromHeaders([$many]);
        self::assertTrue($byCount->has('k' . Baggage::MAX_MEMBERS));
        self::assertFalse($byCount->has('k' . (Baggage::MAX_MEMBERS + 1)));

        $big = implode(',', array_map(static fn (int $i): string => 'k' . $i . '=' . str_repeat('x', 1000), range(1, 10)));
        $byLength = Baggage::fromHeaders([$big]);
        self::assertLessThanOrEqual(Baggage::MAX_LENGTH, strlen((string) $byLength->toHeader()));
        self::assertTrue($byLength->has('k1'));
        self::assertFalse($byLength->has('k10'));
    }

    public function testExceptKeepsTheMembersTheOtherBaggageDoesNotName(): void
    {
        $baggage = Baggage::fromHeaders(['a=1,b=2;p=x,c=3'])->except(Baggage::fromHeaders(['a=9,c=9']));

        self::assertSame('b=2;p=x', $baggage->toHeader());
    }

    public function testEmptyBaggageHasNoHeader(): void
    {
        self::assertTrue(Baggage::empty()->isEmpty());
        self::assertNull(Baggage::empty()->toHeader());
        self::assertTrue(Baggage::fromHeaders([' , ', null])->isEmpty());
    }

    public function testWithReplacesInPlaceAndDropsTheMemberProperties(): void
    {
        $baggage = Baggage::fromHeaders(['a=1,b=2;p=x,c=3'])->with('b', 'new value');

        self::assertSame('a=1,b=new%20value,c=3', $baggage->toHeader());
    }

    public function testWithPrependsANewMember(): void
    {
        self::assertSame('c=3,a=1', Baggage::fromHeaders(['a=1'])->with('c', '3')->toHeader());
    }

    public function testWithKeepsTheAddedMemberWhenTruncating(): void
    {
        $full = implode(',', array_map(static fn (int $i): string => 'k' . $i . '=v', range(1, Baggage::MAX_MEMBERS)));

        $baggage = Baggage::fromHeaders([$full])->with('msstc4symfony.caller', 'app:web');

        self::assertSame('app:web', $baggage->get('msstc4symfony.caller'));
        self::assertFalse($baggage->has('k' . Baggage::MAX_MEMBERS));
    }

    public function testWithDoesNotChangeTheOriginal(): void
    {
        $original = Baggage::fromHeaders(['a=1']);
        $original->with('b', '2');

        self::assertSame('a=1', $original->toHeader());
    }

    public function testWithRejectsAKeyThatIsNotAToken(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Baggage::empty()->with('bad key', 'v');
    }

    public function testWithRejectsAValueThatIsNotUtf8(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Baggage::empty()->with('key', "\xFF");
    }

    public function testNeverEmitsAHeaderBreakingCharacter(): void
    {
        $baggage = Baggage::fromHeaders(["a=1\r\nX-Injected: yes", "b=2\nc=3", 'ok=1'])->with('d', "line\r\nX-Injected: yes");

        $header = (string) $baggage->toHeader();

        self::assertDoesNotMatchRegularExpression('/[\x00-\x1f\x7f]/', $header);
        self::assertSame("line\r\nX-Injected: yes", Baggage::fromHeaders([$header])->get('d'));
    }

    public function testTrimsWhitespaceAroundPropertiesAndKeepsEqualsSignsInValues(): void
    {
        $baggage = Baggage::fromHeaders(['k=a=b ; p = 1 ; flag , q=x;r=c=d']);

        self::assertSame('a=b', $baggage->get('k'));
        self::assertSame('k=a%3Db;p=1;flag,q=x;r=c=d', $baggage->toHeader());
    }

    public function testDropsAMemberWithAnInvalidPropertyValue(): void
    {
        self::assertSame('ok=1', Baggage::fromHeaders(['k=v;p=a="x,ok=1'])->toHeader());
    }

    public function testKeepsAHeaderOfExactlyTheMaximumLength(): void
    {
        $member = 'k=' . str_repeat('x', Baggage::MAX_LENGTH - 2);

        self::assertSame($member, Baggage::fromHeaders([$member])->toHeader());
    }
}
