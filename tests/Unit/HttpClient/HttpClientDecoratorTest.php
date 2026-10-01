<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\HttpClient;

use Msstc4Symfony\TracingBundle\HttpClient\HttpClientDecorator;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HttpClientDecorator::class)]
final class HttpClientDecoratorTest extends TestCase
{
    /** @var list<array<string, string>> */
    private array $sentHeaders = [];

    private MockHttpClient $inner;

    protected function setUp(): void
    {
        $this->inner = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sentHeaders[] = $this->headersOf($options);

            return new MockResponse('');
        });
    }

    public function testAddsTheTraceHeaders(): void
    {
        $decorator = new HttpClientDecorator($this->inner, new RequestIdService('shop', 'api')->setRequestId('abc'));

        $decorator->request('GET', 'https://example.com')->getStatusCode();

        self::assertSame('abc', $this->sentHeaders[0]['request-id'] ?? null);
        self::assertSame('shop:api', $this->sentHeaders[0]['request-from'] ?? null);
    }

    /**
     * @param array<mixed> $headers
     */
    #[DataProvider('provideCallerHeaders')]
    public function testKeepsAHeaderTheCallerSet(array $headers): void
    {
        $decorator = new HttpClientDecorator($this->inner, new RequestIdService('shop', 'api')->setRequestId('abc'));

        $decorator->request('GET', 'https://example.com', ['headers' => $headers])->getStatusCode();

        self::assertSame('mine', $this->sentHeaders[0]['request-id'] ?? null);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function provideCallerHeaders(): iterable
    {
        yield 'name => value' => [['Request-Id' => 'mine']];
        yield 'header line' => [['Request-Id: mine']];
    }

    public function testWithOptionsKeepsTracing(): void
    {
        $decorator = new HttpClientDecorator($this->inner, new RequestIdService('shop', 'api')->setRequestId('abc'));

        $decorator->withOptions(['timeout' => 1])->request('GET', 'https://example.com')->getStatusCode();

        self::assertSame('abc', $this->sentHeaders[0]['request-id'] ?? null);
    }

    /**
     * @param array<mixed> $options
     *
     * @return array<string, string>
     */
    private function headersOf(array $options): array
    {
        $headers = [];
        $lines = $options['headers'] ?? [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (is_string($line) && str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return $headers;
    }
}
