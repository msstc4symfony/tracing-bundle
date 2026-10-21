<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Application;

use InvalidArgumentException;
use Msstc4Symfony\TracingBundle\Application\BaggageRecipients;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(BaggageRecipients::class)]
final class BaggageRecipientsTest extends TestCase
{
    public function testAllowsEveryHostWhenNoListIsGiven(): void
    {
        self::assertTrue((new BaggageRecipients())->allows('anything.example.org'));
        self::assertTrue((new BaggageRecipients())->allows(''));
    }

    public function testTellsWhetherEveryHostIsAllowed(): void
    {
        self::assertTrue((new BaggageRecipients())->allowsEveryHost());
        self::assertFalse((new BaggageRecipients([]))->allowsEveryHost());
        self::assertFalse((new BaggageRecipients(['*.example.com']))->allowsEveryHost());
    }

    public function testAnEmptyListAllowsNoHost(): void
    {
        self::assertFalse((new BaggageRecipients([]))->allows('orders'));
    }

    /**
     * @param list<string> $allowed
     */
    #[DataProvider('provideAllowedHosts')]
    public function testAllowsAListedHost(array $allowed, string $host): void
    {
        self::assertTrue((new BaggageRecipients($allowed))->allows($host));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function provideAllowedHosts(): iterable
    {
        yield 'exact' => [['orders', 'api.internal'], 'api.internal'];
        yield 'host in another case' => [['api.internal'], 'API.Internal'];
        yield 'entry in another case' => [['API.internal'], 'api.internal'];
        yield 'subdomain of a wildcard' => [['*.example.com'], 'a.example.com'];
        yield 'deeper subdomain of a wildcard' => [['*.example.com'], 'b.a.example.com'];
        yield 'wildcard in another case' => [['*.Example.com'], 'A.EXAMPLE.com'];
        yield 'docker service name' => [['orders_api'], 'orders_api'];
        yield 'IPv4' => [['10.0.0.1'], '10.0.0.1'];
        yield 'IPv6' => [['[::1]'], '[::1]'];
        yield 'IPv6 written another way' => [['[0:0::1]'], '[::1]'];
        yield 'IPv6 host written another way' => [['[::1]'], '[0:0:0:0:0:0:0:1]'];
        yield 'IPv6 in another case' => [['[FD00::7]'], '[fd00::7]'];
        yield 'host after a wildcard' => [['*.example.com', 'orders'], 'orders'];
        yield 'fully qualified host' => [['orders.example.com'], 'orders.example.com.'];
        yield 'fully qualified entry' => [['orders.example.com.'], 'orders.example.com'];
        yield 'fully qualified wildcard' => [['*.example.com.'], 'a.example.com'];
        yield 'fully qualified subdomain' => [['*.example.com'], 'a.example.com.'];
    }

    /**
     * @param list<string> $allowed
     */
    #[DataProvider('provideOtherHosts')]
    public function testRejectsAHostNotListed(array $allowed, string $host): void
    {
        self::assertFalse((new BaggageRecipients($allowed))->allows($host));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function provideOtherHosts(): iterable
    {
        yield 'other host' => [['orders'], 'payments'];
        yield 'subdomain of an exact entry' => [['example.com'], 'a.example.com'];
        yield 'parent of an exact entry' => [['a.example.com'], 'example.com'];
        yield 'domain of a wildcard itself' => [['*.example.com'], 'example.com'];
        yield 'suffix without a dot' => [['*.example.com'], 'badexample.com'];
        yield 'empty host' => [['orders'], ''];
        yield 'invalid IPv6 host' => [['[::1]'], '[::zz]'];
        yield 'host with two trailing dots' => [['orders.example.com'], 'orders.example.com..'];
        yield 'IPv6 host without brackets' => [['[::1]'], 'x::1x'];
    }

    #[TestWith(['orders:8080', '"orders:8080" is not a host, an IP address or "*.domain".'])]
    #[TestWith([null, 'null is not a host, an IP address or "*.domain".'])]
    public function testNamesTheInvalidEntry(?string $entry, string $message): void
    {
        $this->expectExceptionMessage($message);

        new BaggageRecipients([$entry]);
    }

    /**
     * @param list<string|null> $allowed
     */
    #[DataProvider('provideInvalidLists')]
    public function testRejectsAnInvalidEntry(array $allowed): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaggageRecipients($allowed);
    }

    /**
     * @return iterable<string, array{list<string|null>}>
     */
    public static function provideInvalidLists(): iterable
    {
        yield 'empty' => [['']];
        yield 'blank' => [[' ']];
        yield 'padded' => [[' orders']];
        yield 'bare wildcard' => [['*']];
        yield 'wildcard inside' => [['a.*.example.com']];
        yield 'wildcard without a dot' => [['*example.com']];
        yield 'port' => [['orders:8080']];
        yield 'url' => [['https://orders']];
        yield 'empty label' => [['a..example.com']];
        yield 'wildcard over an IPv6' => [['*.[::1]']];
        yield 'wildcard over numeric labels' => [['*.0.1']];
        yield 'wildcard over a number' => [['*.1']];
        yield 'invalid IPv6' => [['[:]']];
        yield 'IPv6 without brackets' => [['x::1x']];
        yield 'IPv6 with one bracket' => [['[::1x']];
        yield 'IPv6 of dots' => [['[.]']];
        yield 'two trailing dots' => [['orders..']];
        yield 'unset environment variable' => [[null]];
        yield 'one bad among good' => [['orders', 'bad host']];
    }
}
