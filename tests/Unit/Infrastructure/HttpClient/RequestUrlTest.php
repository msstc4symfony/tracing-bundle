<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Infrastructure\HttpClient;

use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\ClientOptions;
use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\RequestUrl;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClientTrait;

#[CoversClass(RequestUrl::class)]
#[UsesClass(ClientOptions::class)]
final class RequestUrlTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        if (!trait_exists(HttpClientTrait::class)) {
            self::markTestSkipped('symfony/http-client is not installed');
        }
    }

    /**
     * @param string|array<mixed>|null $baseUri
     */
    #[TestWith(['http://Orders/x', null, 'http://orders/x', 'http://orders', 'orders'])]
    #[TestWith(['https://user:p@ss@orders:8443/x?a=1', null, 'https://user:p@ss@orders:8443/x?a=1', 'https://user:p@ss@orders:8443', 'orders'])]
    #[TestWith(['http://a@b@evil/x', null, 'http://a@b@evil/x', 'http://a@b@evil', 'evil'])]
    #[TestWith(['http://orders:80/', null, 'http://orders/', 'http://orders', 'orders'])]
    #[TestWith(['http://[::1]:8080/x', null, 'http://[::1]:8080/x', 'http://[::1]:8080', '[::1]'])]
    #[TestWith(['/x', 'http://orders/api/', 'http://orders/x', 'http://orders', 'orders'])]
    #[TestWith(['y', 'http://orders/api/', 'http://orders/api/y', 'http://orders', 'orders'])]
    #[TestWith(['//evil/x', 'http://orders/', 'http://evil/x', 'http://evil', 'evil'])]
    #[TestWith(['/x', ['scheme' => 'http:', 'authority' => '//orders', 'path' => '/', 'query' => null, 'fragment' => null], 'http://orders/x', 'http://orders', 'orders'])]
    public function testReadsTheUrlAsTheTransport(string $url, string|array|null $baseUri, string $absolute, string $origin, string $host): void
    {
        $resolved = RequestUrl::resolve($url, $baseUri);

        self::assertInstanceOf(RequestUrl::class, $resolved);
        self::assertSame($absolute, $resolved->url);
        self::assertSame($origin, $resolved->origin);
        self::assertSame($host, $resolved->host);
    }

    public function testReadsAnInternationalHostInPunycode(): void
    {
        if (!defined('INTL_IDNA_VARIANT_UTS46')) {
            self::markTestSkipped('ext-intl or symfony/polyfill-intl-idn is not installed');
        }

        self::assertSame('xn--bcher-kva.example', RequestUrl::resolve('http://bücher.example/')?->host);
    }

    /**
     * @param string|array<mixed>|null $baseUri
     */
    #[TestWith(['http://evil\\@orders/'])]
    #[TestWith(["http://orders/\r\nx"])]
    #[TestWith(['ftp://orders/'])]
    #[TestWith(['/x'])]
    #[TestWith(['orders/x'])]
    #[TestWith(['/x', 'not a url'])]
    #[TestWith(['/x', ['scheme' => 'http:', 'authority' => 'orders', 'path' => '/', 'query' => null, 'fragment' => null]])]
    public function testRejectsWhatTheTransportRejects(string $url, string|array|null $baseUri = null): void
    {
        self::assertNull(RequestUrl::resolve($url, $baseUri));
    }
}
