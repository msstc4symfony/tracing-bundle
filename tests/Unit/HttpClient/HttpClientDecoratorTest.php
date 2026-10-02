<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\HttpClient;

use ArrayIterator;
use Msstc4Symfony\TracingBundle\HttpClient\HttpClientDecorator;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HttpClientDecorator::class)]
#[UsesClass(RequestIdService::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
final class HttpClientDecoratorTest extends TestCase
{
    /** @var list<array<string, string>> */
    private array $sentHeaders = [];

    private MockHttpClient $inner;

    protected function setUp(): void
    {
        if (!class_exists(MockHttpClient::class)) {
            self::markTestSkipped('symfony/http-client is not installed');
        }

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

    public function testKeepsARequestFromTheCallerSet(): void
    {
        $decorator = new HttpClientDecorator($this->inner, new RequestIdService('shop', 'api')->setRequestId('abc'));

        $decorator->request('GET', 'https://example.com', ['headers' => ['Request-From: gateway:edge']])->getStatusCode();

        self::assertSame('abc', $this->sentHeaders[0]['request-id'] ?? null);
        self::assertSame('gateway:edge', $this->sentHeaders[0]['request-from'] ?? null);
    }

    public function testKeepsCallerHeadersGivenAsTraversable(): void
    {
        $decorator = new HttpClientDecorator($this->inner, new RequestIdService('shop', 'api')->setRequestId('abc'));

        $decorator->request('GET', 'https://example.com', ['headers' => new ArrayIterator(['Authorization' => 'Bearer x'])])->getStatusCode();

        self::assertSame('Bearer x', $this->sentHeaders[0]['authorization'] ?? null);
        self::assertSame('abc', $this->sentHeaders[0]['request-id'] ?? null);
    }

    public function testWithOptionsKeepsTracing(): void
    {
        $decorator = new HttpClientDecorator($this->inner, new RequestIdService('shop', 'api')->setRequestId('abc'));

        $decorator->withOptions(['timeout' => 1])->request('GET', 'https://example.com')->getStatusCode();

        self::assertSame('abc', $this->sentHeaders[0]['request-id'] ?? null);
    }

    public function testSendsTheW3cTraceWithANewSpanPerRequest(): void
    {
        $storage = $this->continuedTrace();
        $decorator = new HttpClientDecorator($this->inner, $storage, $storage);

        $decorator->request('GET', 'https://example.com')->getStatusCode();
        $decorator->request('GET', 'https://example.com')->getStatusCode();

        $first = TraceParent::fromHeader($this->sentHeaders[0]['traceparent'] ?? '');
        $second = TraceParent::fromHeader($this->sentHeaders[1]['traceparent'] ?? '');
        self::assertInstanceOf(TraceParent::class, $first);
        self::assertInstanceOf(TraceParent::class, $second);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $first->traceId);
        self::assertSame(0, $first->flags);
        self::assertSame($first->traceId, $second->traceId);
        self::assertNotSame($first->parentId, $second->parentId);
        self::assertNotContains($first->parentId, ['00f067aa0ba902b7', $storage->getTraceParent()->parentId]);
        self::assertSame('rojo=1', $this->sentHeaders[0]['tracestate'] ?? null);
    }

    public function testStartsAW3cTraceWhenNoneWasReceived(): void
    {
        $storage = new RequestIdService('shop', 'api');

        new HttpClientDecorator($this->inner, $storage, $storage)->request('GET', 'https://example.com')->getStatusCode();

        self::assertMatchesRegularExpression('/^00-' . $storage->getTraceParent()->traceId . '-[0-9a-f]{16}-01$/', $this->sentHeaders[0]['traceparent'] ?? '');
        self::assertArrayNotHasKey('tracestate', $this->sentHeaders[0]);
    }

    public function testKeepsATraceParentTheCallerSet(): void
    {
        $storage = $this->continuedTrace();

        new HttpClientDecorator($this->inner, $storage, $storage)
            ->request('GET', 'https://example.com', ['headers' => ['TraceParent: mine']])
            ->getStatusCode()
        ;

        self::assertSame('mine', $this->sentHeaders[0]['traceparent'] ?? null);
        self::assertArrayNotHasKey('tracestate', $this->sentHeaders[0]);
    }

    public function testKeepsATraceStateTheCallerSet(): void
    {
        $storage = $this->continuedTrace();

        new HttpClientDecorator($this->inner, $storage, $storage)
            ->request('GET', 'https://example.com', ['headers' => ['tracestate' => 'mine=1']])
            ->getStatusCode()
        ;

        self::assertSame('mine=1', $this->sentHeaders[0]['tracestate'] ?? null);
        self::assertArrayHasKey('traceparent', $this->sentHeaders[0]);
    }

    public function testSendsNoW3cHeadersWhenDisabled(): void
    {
        new HttpClientDecorator($this->inner, $this->continuedTrace())->request('GET', 'https://example.com')->getStatusCode();

        self::assertArrayNotHasKey('traceparent', $this->sentHeaders[0]);
        self::assertArrayNotHasKey('tracestate', $this->sentHeaders[0]);
    }

    private function continuedTrace(): RequestIdService
    {
        $received = TraceParent::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00');
        self::assertInstanceOf(TraceParent::class, $received);

        return new RequestIdService('shop', 'api')->continueTrace($received, TraceState::fromHeaders(['rojo=1']));
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
