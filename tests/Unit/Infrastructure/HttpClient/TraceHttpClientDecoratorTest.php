<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Infrastructure\HttpClient;

use ArrayIterator;
use Msstc4Symfony\TracingBundle\Application\CallerName;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\OutgoingTrace;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\TraceHttpClientDecorator;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\ScopingHttpClient;

#[CoversClass(TraceHttpClientDecorator::class)]
#[UsesClass(InMemoryTraceContextHolder::class)]
#[UsesClass(CallerName::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(OutgoingTrace::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
#[UsesClass(Baggage::class)]
final class TraceHttpClientDecoratorTest extends TestCase
{
    private const RECEIVED = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00';

    /** @var list<array<string, list<string>>> */
    private array $sentHeaders = [];

    private MockHttpClient $inner;

    private InMemoryTraceContextHolder $holder;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(MockHttpClient::class)) {
            self::markTestSkipped('symfony/http-client is not installed');
        }

        $this->holder = new InMemoryTraceContextHolder();
        $this->inner = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sentHeaders[] = $this->headersOf($options);

            return new MockResponse('');
        });
    }

    public function testSendsTheTraceAndTheBaggageWithThisServiceAsCaller(): void
    {
        $this->holder->replace($this->holder->current()->withBaggage(Baggage::fromHeaders(['user=alice'])));

        $this->send([]);

        self::assertSame([$this->holder->current()->traceParent->toHeader()], $this->sentHeaders[0]['traceparent'] ?? null);
        self::assertSame(['msstc4symfony.caller=orders%3Aapi,user=alice'], $this->sentHeaders[0]['baggage'] ?? null);
        self::assertArrayNotHasKey('tracestate', $this->sentHeaders[0]);
    }

    public function testSendsThisUnitsSpanOfTheReceivedTrace(): void
    {
        $this->continueReceivedTrace();

        $this->send([]);
        $this->send([]);

        $first = TraceParent::fromHeader($this->sentHeaders[0]['traceparent'][0] ?? '');
        self::assertInstanceOf(TraceParent::class, $first);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $first->traceId);
        self::assertSame(0, $first->flags);
        self::assertSame($this->holder->current()->traceParent->spanId, $first->spanId);
        self::assertSame($this->sentHeaders[0]['traceparent'], $this->sentHeaders[1]['traceparent'] ?? null);
        self::assertSame(['rojo=1'], $this->sentHeaders[0]['tracestate'] ?? null);
    }

    /**
     * @param array<mixed> $headers
     */
    #[DataProvider('provideCallerTraceParents')]
    public function testKeepsATraceParentTheCallerSetWithoutOurTraceState(array $headers): void
    {
        $this->continueReceivedTrace();

        $this->send(['headers' => $headers]);

        self::assertSame(['mine'], $this->sentHeaders[0]['traceparent'] ?? null);
        self::assertArrayNotHasKey('tracestate', $this->sentHeaders[0]);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function provideCallerTraceParents(): iterable
    {
        yield 'name => value' => [['TraceParent' => 'mine']];
        yield 'header line' => [['TraceParent: mine']];
    }

    public function testKeepsATraceStateTheCallerSet(): void
    {
        $this->continueReceivedTrace();

        $this->send(['headers' => ['tracestate' => 'mine=1']]);

        self::assertSame(['mine=1'], $this->sentHeaders[0]['tracestate'] ?? null);
        self::assertArrayHasKey('traceparent', $this->sentHeaders[0]);
    }

    public function testAddsTheCallerToABaggageTheCallerSet(): void
    {
        $this->send(['headers' => ['Baggage' => 'tenant=7']]);

        self::assertSame(['tenant=7, msstc4symfony.caller=orders%3Aapi'], $this->sentHeaders[0]['baggage'] ?? null);
    }

    public function testAddsTheCallerToABaggageLineTheCallerSet(): void
    {
        $this->send(['headers' => ['baggage: tenant=7']]);

        self::assertSame(['tenant=7, msstc4symfony.caller=orders%3Aapi'], $this->sentHeaders[0]['baggage'] ?? null);
    }

    public function testKeepsACallerMemberTheCallerSet(): void
    {
        $this->send(['headers' => ['baggage' => ['msstc4symfony.caller=batch%3Ajob']]]);

        self::assertSame(['msstc4symfony.caller=batch%3Ajob'], $this->sentHeaders[0]['baggage'] ?? null);
    }

    public function testKeepsCallerHeadersGivenAsTraversable(): void
    {
        $this->send(['headers' => new ArrayIterator(['Authorization' => 'Bearer x'])]);

        self::assertSame(['Bearer x'], $this->sentHeaders[0]['authorization'] ?? null);
        self::assertArrayHasKey('traceparent', $this->sentHeaders[0]);
    }

    public function testWithOptionsKeepsTracing(): void
    {
        $this->decorator()->withOptions(['timeout' => 1])->request('GET', 'https://example.com')->getStatusCode();

        self::assertArrayHasKey('traceparent', $this->sentHeaders[0]);
    }

    public function testLeavesTheCallersOtherHeadersAlone(): void
    {
        $this->send(['headers' => ['Authorization' => 'Bearer x', 'baggage' => 'tenant=7']]);

        self::assertSame(['Bearer x'], $this->sentHeaders[0]['authorization'] ?? null);
        self::assertSame(['tenant=7, msstc4symfony.caller=orders%3Aapi'], $this->sentHeaders[0]['baggage'] ?? null);
        self::assertSame([$this->holder->current()->traceParent->toHeader()], $this->sentHeaders[0]['traceparent'] ?? null);
    }

    public function testFindsTheCallersHeaderLinesAmongOthers(): void
    {
        $this->send(['headers' => ['Authorization: Bearer x', 'traceparent: mine', 'baggage: tenant=7:eu, msstc4symfony.caller=batch%3Ajob']]);

        self::assertSame(['Bearer x'], $this->sentHeaders[0]['authorization'] ?? null);
        self::assertSame(['mine'], $this->sentHeaders[0]['traceparent'] ?? null);
        self::assertSame(['tenant=7:eu, msstc4symfony.caller=batch%3Ajob'], $this->sentHeaders[0]['baggage'] ?? null);
    }

    public function testAddsTheCallerAfterSeveralBaggageValuesTheCallerSet(): void
    {
        $this->send(['headers' => ['baggage' => ['a=1', 'b=2']]]);

        self::assertSame(['a=1, b=2, msstc4symfony.caller=orders%3Aapi'], $this->sentHeaders[0]['baggage'] ?? null);
    }

    public function testSendsTheCallerWhenNoBaggageWasReceived(): void
    {
        $this->send(['headers' => ['Authorization: Bearer x']]);

        self::assertSame(['Bearer x'], $this->sentHeaders[0]['authorization'] ?? null);
        self::assertSame(['msstc4symfony.caller=orders%3Aapi'], $this->sentHeaders[0]['baggage'] ?? null);
        self::assertArrayHasKey('traceparent', $this->sentHeaders[0]);
    }

    public function testKeepsEveryBaggageValueOfAScopedClient(): void
    {
        ScopingHttpClient::forBaseUri($this->decorator(), 'https://example.com/')
            ->request('GET', '/a', ['headers' => ['baggage' => ['a=1', 'b=2']]])
            ->getStatusCode()
        ;

        self::assertSame(['a=1, b=2, msstc4symfony.caller=orders%3Aapi'], $this->sentHeaders[0]['baggage'] ?? null);
    }

    public function testReadsAStringableBaggageValue(): void
    {
        $value = new class implements Stringable {
            #[Override]
            public function __toString(): string
            {
                return 'tenant=7';
            }
        };

        $this->send(['headers' => ['baggage' => $value]]);

        self::assertSame(['tenant=7, msstc4symfony.caller=orders%3Aapi'], $this->sentHeaders[0]['baggage'] ?? null);
    }

    public function testAddsTheReceivedMembersTheCallersBaggageDoesNotName(): void
    {
        $this->holder->replace($this->holder->current()->withBaggage(Baggage::fromHeaders(['user=alice,tenant=1'])));

        // As Sentry sets its own members on the client before the transport.
        $this->send(['headers' => ['baggage' => 'sentry-trace_id=abc,tenant=7']]);

        self::assertSame(['sentry-trace_id=abc,tenant=7, msstc4symfony.caller=orders%3Aapi,user=alice'], $this->sentHeaders[0]['baggage'] ?? null);
    }

    /**
     * @param array<mixed> $options
     */
    private function send(array $options): void
    {
        $this->decorator()->request('GET', 'https://example.com', $options)->getStatusCode();
    }

    private function decorator(): TraceHttpClientDecorator
    {
        return new TraceHttpClientDecorator($this->inner, $this->holder, new CallerName('orders', 'api'));
    }

    private function continueReceivedTrace(): void
    {
        $received = TraceParent::fromHeader(self::RECEIVED);
        self::assertInstanceOf(TraceParent::class, $received);

        $this->holder->replace($this->holder->current()->continuing($received, TraceState::fromHeaders(['rojo=1']), Baggage::empty()));
    }

    /**
     * @param array<mixed> $options
     *
     * @return array<string, list<string>>
     */
    private function headersOf(array $options): array
    {
        $headers = [];
        $lines = $options['headers'] ?? [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (is_string($line) && str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))][] = trim($value);
            }
        }

        return $headers;
    }
}
