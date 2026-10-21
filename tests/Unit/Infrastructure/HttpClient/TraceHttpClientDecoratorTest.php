<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Infrastructure\HttpClient;

use ArrayIterator;
use Msstc4Symfony\TracingBundle\Application\BaggageRecipients;
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
#[UsesClass(BaggageRecipients::class)]
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

    public function testSendsNoBaggageToAHostNotAllowed(): void
    {
        $this->holder->replace($this->holder->current()->withBaggage(Baggage::fromHeaders(['user=alice'])));

        $this->send([], new BaggageRecipients(['orders']));

        self::assertArrayNotHasKey('baggage', $this->sentHeaders[0]);
        self::assertSame([$this->holder->current()->traceParent->toHeader()], $this->sentHeaders[0]['traceparent'] ?? null);
    }

    public function testSendsTheTraceStateToAHostNotAllowed(): void
    {
        $this->continueReceivedTrace();

        $this->send([], new BaggageRecipients([]));

        self::assertSame(['rojo=1'], $this->sentHeaders[0]['tracestate'] ?? null);
    }

    public function testSendsTheCallersBaggageUnchangedToAHostNotAllowed(): void
    {
        $this->holder->replace($this->holder->current()->withBaggage(Baggage::fromHeaders(['user=alice'])));

        $this->send(['headers' => ['baggage' => ['sentry-trace_id=abc', 'tenant=7']]], new BaggageRecipients([]));

        self::assertSame(['sentry-trace_id=abc', 'tenant=7'], $this->sentHeaders[0]['baggage'] ?? null);
    }

    public function testSendsTheBaggageToAnAllowedHostOnAnyPort(): void
    {
        $this->decorator(new BaggageRecipients(['*.example.com']))->request('GET', 'https://API.example.com:8443/orders?x=1')->getStatusCode();

        self::assertSame(['msstc4symfony.caller=orders%3Aapi'], $this->sentHeaders[0]['baggage'] ?? null);
    }

    public function testTakesTheHostOfTheBaseUriForARelativeUrl(): void
    {
        $recipients = new BaggageRecipients(['orders']);

        $this->decorator($recipients)->request('GET', '/x', ['base_uri' => 'http://orders:8080/api/'])->getStatusCode();
        $this->decorator($recipients)->request('GET', '/x', ['base_uri' => 'http://payments/'])->getStatusCode();

        self::assertArrayHasKey('baggage', $this->sentHeaders[0]);
        self::assertArrayNotHasKey('baggage', $this->sentHeaders[1]);
    }

    public function testTakesTheHostOfABaseUriSetWithOptions(): void
    {
        $decorator = $this->decorator(new BaggageRecipients(['orders']));

        $decorator->withOptions(['base_uri' => 'http://orders/'])->request('GET', '/x')->getStatusCode();
        $decorator->withOptions(['base_uri' => 'http://orders/'])->withOptions(['timeout' => 1])->request('GET', '/x')->getStatusCode();
        $decorator->withOptions(['base_uri' => 'http://payments/'])->request('GET', '/x')->getStatusCode();
        $decorator->withOptions(['base_uri' => 'http://payments/'])->request('GET', '/x', ['base_uri' => 'http://orders/'])->getStatusCode();
        $decorator->request('GET', '/x')->getStatusCode();

        self::assertArrayHasKey('baggage', $this->sentHeaders[0]);
        self::assertArrayHasKey('baggage', $this->sentHeaders[1]);
        self::assertArrayNotHasKey('baggage', $this->sentHeaders[2]);
        self::assertArrayHasKey('baggage', $this->sentHeaders[3]);
        self::assertArrayNotHasKey('baggage', $this->sentHeaders[4], 'withOptions() returns a copy.');
    }

    public function testTakesTheHostOfAnAbsoluteUrlOverTheBaseUri(): void
    {
        $this->decorator(new BaggageRecipients(['orders']))->request('GET', 'http://payments/x', ['base_uri' => 'http://orders/'])->getStatusCode();

        self::assertArrayNotHasKey('baggage', $this->sentHeaders[0]);
    }

    public function testSendsNoBaggageToAUrlWithoutAHostWhenHostsAreListed(): void
    {
        $this->decorator(new BaggageRecipients(['example.com']))->request('GET', 'not a url')->getStatusCode();

        self::assertArrayNotHasKey('baggage', $this->sentHeaders[0]);
    }

    /**
     * @param array<mixed> $options
     */
    private function send(array $options, BaggageRecipients $recipients = new BaggageRecipients()): void
    {
        $this->decorator($recipients)->request('GET', 'https://example.com', $options)->getStatusCode();
    }

    private function decorator(BaggageRecipients $recipients = new BaggageRecipients()): TraceHttpClientDecorator
    {
        return new TraceHttpClientDecorator($this->inner, $this->holder, new CallerName('orders', 'api'), $recipients);
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
