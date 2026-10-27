<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Infrastructure\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LogicException;
use Msstc4Symfony\TracingBundle\Application\BaggageRecipients;
use Msstc4Symfony\TracingBundle\Application\CallerName;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\OutgoingTrace;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryOutgoingSpanReservation;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use Msstc4Symfony\TracingBundle\Infrastructure\Guzzle\TraceGuzzleMiddleware;
use Msstc4Symfony\TracingBundle\Test\Unit\Infrastructure\Guzzle\Fixture\ClientWithoutConfig;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;

/**
 * @phpstan-import-type GuzzleHandler from TraceGuzzleMiddleware
 */
#[CoversClass(TraceGuzzleMiddleware::class)]
#[UsesClass(InMemoryTraceContextHolder::class)]
#[UsesClass(InMemoryOutgoingSpanReservation::class)]
#[UsesClass(CallerName::class)]
#[UsesClass(BaggageRecipients::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(OutgoingTrace::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
#[UsesClass(Baggage::class)]
final class TraceGuzzleMiddlewareTest extends TestCase
{
    private const RECEIVED = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00';

    private MockHandler $transport;

    private Client $client;

    private InMemoryTraceContextHolder $holder;

    private InMemoryOutgoingSpanReservation $reservation;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(Client::class)) {
            self::markTestSkipped('guzzlehttp/guzzle is not installed');
        }

        $this->holder = new InMemoryTraceContextHolder();
        $this->reservation = new InMemoryOutgoingSpanReservation($this->holder);
        $this->transport = new MockHandler([new Response(), new Response()]);
        $this->client = new Client(['handler' => HandlerStack::create($this->transport)]);
    }

    public function testAddsTheTraceHeadersOnce(): void
    {
        $this->holder->replace($this->holder->current()->withBaggage(Baggage::fromHeaders(['user=alice'])));
        $middleware = $this->middleware();
        $middleware->addHandler($this->client);
        $middleware->addHandler($this->client);

        $stack = $this->client->getConfig('handler');
        self::assertInstanceOf(HandlerStack::class, $stack);
        self::assertSame(1, $this->tracingMiddlewareCount($stack));
        $this->client->request('GET', 'https://example.com');

        $request = $this->lastRequest();
        self::assertSame([$this->holder->current()->traceParent->toHeader()], $request->getHeader('traceparent'));
        self::assertSame(['msstc4symfony.caller=orders%3Aapi,user=alice'], $request->getHeader('baggage'));
        self::assertFalse($request->hasHeader('tracestate'));
    }

    public function testSendsTheReservedSpanOnceThenTheCurrentOne(): void
    {
        $reserved = $this->reservation->reserve();
        $this->middleware()->addHandler($this->client);

        $this->client->request('GET', 'https://example.com');
        self::assertSame([$reserved->traceParent->toHeader()], $this->lastRequest()->getHeader('traceparent'));

        $this->client->request('GET', 'https://example.com');
        self::assertSame([$this->holder->current()->traceParent->toHeader()], $this->lastRequest()->getHeader('traceparent'));
    }

    public function testATraceParentTheCallerSetDropsTheReservation(): void
    {
        $this->reservation->reserve();
        $this->middleware()->addHandler($this->client);

        $this->client->request('GET', 'https://example.com', ['headers' => ['traceparent' => 'mine']]);

        self::assertSame(['mine'], $this->lastRequest()->getHeader('traceparent'));
        self::assertNull($this->reservation->take());
    }

    public function testARedirectAfterTheReservedSpanSendsTheUnitsSpan(): void
    {
        $this->transport->reset();
        $this->transport->append(new Response(302, ['Location' => 'http://payments/y']), new Response());

        $reserved = $this->reservation->reserve();
        $this->middleware()->addHandler($this->client);

        $this->client->request('GET', 'http://orders/x');

        self::assertSame('payments', $this->lastRequest()->getUri()->getHost());
        self::assertSame([$this->holder->current()->traceParent->toHeader()], $this->lastRequest()->getHeader('traceparent'));
        self::assertNotSame($reserved->traceParent->toHeader(), $this->holder->current()->traceParent->toHeader());
    }

    public function testWorksWithoutAReservation(): void
    {
        (new TraceGuzzleMiddleware($this->holder, new CallerName('orders', 'api')))->addHandler($this->client);

        $this->client->request('GET', 'https://example.com');

        self::assertSame([$this->holder->current()->traceParent->toHeader()], $this->lastRequest()->getHeader('traceparent'));
    }

    public function testAddsTheCallerToABaggageTheCallerSet(): void
    {
        $this->middleware()->addHandler($this->client);

        $this->client->request('GET', 'https://example.com', ['headers' => ['baggage' => 'tenant=7']]);

        self::assertSame(['tenant=7', 'msstc4symfony.caller=orders%3Aapi'], $this->lastRequest()->getHeader('baggage'));
    }

    public function testAddsTheReceivedMembersTheCallersBaggageDoesNotName(): void
    {
        $this->holder->replace($this->holder->current()->withBaggage(Baggage::fromHeaders(['user=alice,tenant=1'])));
        $this->middleware()->addHandler($this->client);

        $this->client->request('GET', 'https://example.com', ['headers' => ['baggage' => 'sentry-trace_id=abc,tenant=7']]);

        self::assertSame(['sentry-trace_id=abc,tenant=7', 'msstc4symfony.caller=orders%3Aapi,user=alice'], $this->lastRequest()->getHeader('baggage'));
    }

    public function testKeepsACallerMemberTheCallerSet(): void
    {
        $this->middleware()->addHandler($this->client);

        $this->client->request('GET', 'https://example.com', ['headers' => ['baggage' => 'msstc4symfony.caller=batch%3Ajob']]);

        self::assertSame(['msstc4symfony.caller=batch%3Ajob'], $this->lastRequest()->getHeader('baggage'));
    }

    public function testIgnoresClientsWithoutAHandlerStack(): void
    {
        $client = new Client(['handler' => new MockHandler([new Response()])]);

        $this->middleware()->addHandler($client);

        self::assertNotInstanceOf(HandlerStack::class, $client->getConfig('handler'));
    }

    public function testDecoratesTheGivenHandlerStack(): void
    {
        $stack = HandlerStack::create($this->transport);

        $decorated = $this->middleware()->decorateHandler($stack);
        (new Client(['handler' => $decorated]))->request('GET', 'https://example.com');

        self::assertSame($stack, $decorated);
        self::assertTrue($this->lastRequest()->hasHeader('traceparent'));
    }

    public function testDecoratesTheGivenHandlerStackOnce(): void
    {
        $middleware = $this->middleware();
        $stack = HandlerStack::create($this->transport);
        $middleware->decorateHandler($middleware->decorateHandler($stack));

        self::assertSame(1, $this->tracingMiddlewareCount($stack));
        (new Client(['handler' => $stack]))->request('GET', 'https://example.com');

        self::assertTrue($this->lastRequest()->hasHeader('traceparent'));
    }

    public function testCreatesTheDefaultStackWhenNoHandlerIsGiven(): void
    {
        $stack = $this->middleware()->decorateHandler();
        self::assertInstanceOf(HandlerStack::class, $stack);
        $stack->setHandler($this->transport);

        (new Client(['handler' => $stack]))->request('GET', 'https://example.com');

        self::assertTrue($this->lastRequest()->hasHeader('traceparent'));
    }

    public function testLeavesABareHandlerUnchanged(): void
    {
        self::assertSame($this->transport, $this->middleware()->decorateHandler($this->transport));
    }

    public function testMiddlewareCanBePushedOntoAnyStack(): void
    {
        $stack = HandlerStack::create($this->transport);
        $stack->push($this->middleware()->middleware());

        (new Client(['handler' => $stack]))->request('GET', 'https://example.com');

        self::assertTrue($this->lastRequest()->hasHeader('traceparent'));
    }

    public function testRefusesAClientWithoutGetConfig(): void
    {
        if (method_exists(ClientInterface::class, 'getConfig')) {
            self::markTestSkipped('Guzzle 7 clients always have getConfig()');
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('decorateHandler()');

        $this->middleware()->addHandler(new ClientWithoutConfig());
    }

    public function testSendsThisUnitsSpanOfTheReceivedTrace(): void
    {
        $this->continueReceivedTrace();
        $this->middleware()->addHandler($this->client);

        $this->client->request('GET', 'https://example.com');
        $first = TraceParent::fromHeader($this->lastRequest()->getHeaderLine('traceparent'));
        $state = $this->lastRequest()->getHeaderLine('tracestate');
        $this->client->request('GET', 'https://example.com');
        $second = TraceParent::fromHeader($this->lastRequest()->getHeaderLine('traceparent'));

        self::assertInstanceOf(TraceParent::class, $first);
        self::assertInstanceOf(TraceParent::class, $second);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $first->traceId);
        self::assertSame(0, $first->flags);
        self::assertSame($this->holder->current()->traceParent->spanId, $first->spanId);
        self::assertSame($first->toHeader(), $second->toHeader());
        self::assertSame('rojo=1', $state);
    }

    public function testKeepsATraceParentTheCallerSet(): void
    {
        $this->continueReceivedTrace();
        $this->middleware()->addHandler($this->client);

        $this->client->request('GET', 'https://example.com', ['headers' => ['traceparent' => 'mine']]);

        $request = $this->lastRequest();
        self::assertSame(['mine'], $request->getHeader('traceparent'));
        self::assertFalse($request->hasHeader('tracestate'));
    }

    public function testKeepsATraceStateTheCallerSet(): void
    {
        $this->continueReceivedTrace();
        $this->middleware()->addHandler($this->client);

        $this->client->request('GET', 'https://example.com', ['headers' => ['tracestate' => 'mine=1']]);

        $request = $this->lastRequest();
        self::assertSame(['mine=1'], $request->getHeader('tracestate'));
        self::assertTrue($request->hasHeader('traceparent'));
    }

    public function testSendsNoBaggageToAHostNotAllowed(): void
    {
        $this->continueReceivedTrace();
        $this->holder->replace($this->holder->current()->withBaggage(Baggage::fromHeaders(['user=alice'])));
        $this->middleware(new BaggageRecipients(['orders']))->addHandler($this->client);

        $this->client->request('GET', 'https://example.com');

        $request = $this->lastRequest();
        self::assertFalse($request->hasHeader('baggage'));
        self::assertTrue($request->hasHeader('traceparent'));
        self::assertSame(['rojo=1'], $request->getHeader('tracestate'));
    }

    public function testSendsTheCallersBaggageUnchangedToAHostNotAllowed(): void
    {
        $this->middleware(new BaggageRecipients([]))->addHandler($this->client);

        $this->client->request('GET', 'https://example.com', ['headers' => ['baggage' => 'sentry-trace_id=abc']]);

        self::assertSame(['sentry-trace_id=abc'], $this->lastRequest()->getHeader('baggage'));
    }

    public function testSendsTheBaggageToAnAllowedHostOnAnyPort(): void
    {
        $this->middleware(new BaggageRecipients(['*.example.com']))->addHandler($this->client);

        $this->client->request('GET', 'https://API.example.com:8443/orders');

        self::assertSame(['msstc4symfony.caller=orders%3Aapi'], $this->lastRequest()->getHeader('baggage'));
    }

    public function testDropsItsBaggageWhenARedirectLeavesTheAllowedHosts(): void
    {
        $this->holder->replace($this->holder->current()->withBaggage(Baggage::fromHeaders(['user=alice'])));
        $this->transport->reset();
        $this->transport->append(new Response(302, ['Location' => 'https://evil.example/collect']), new Response());
        $this->middleware(new BaggageRecipients(['orders']))->addHandler($this->client);

        $this->client->request('GET', 'http://orders/x', ['headers' => ['baggage' => 'tenant=7']]);

        $request = $this->lastRequest();
        self::assertSame('evil.example', $request->getUri()->getHost());
        self::assertSame(['tenant=7'], $request->getHeader('baggage'));
        self::assertTrue($request->hasHeader('traceparent'));
    }

    public function testSendsItsBaggageOnceAcrossRedirectsBetweenAllowedHosts(): void
    {
        $this->transport->reset();
        $this->transport->append(new Response(302, ['Location' => 'http://payments/y']), new Response());
        $this->middleware(new BaggageRecipients(['orders', 'payments']))->addHandler($this->client);

        $this->client->request('GET', 'http://orders/x');

        self::assertSame(['msstc4symfony.caller=orders%3Aapi'], $this->lastRequest()->getHeader('baggage'));
    }

    public function testSendsNoBaggageOfItsOwnWhenARedirectLeavesAHostWithoutCallerBaggage(): void
    {
        $this->transport->reset();
        $this->transport->append(new Response(302, ['Location' => 'http://evil.example/y']), new Response());
        $this->middleware(new BaggageRecipients(['orders']))->addHandler($this->client);

        $this->client->request('GET', 'http://orders/x');

        self::assertFalse($this->lastRequest()->hasHeader('baggage'));
    }

    private function middleware(BaggageRecipients $recipients = new BaggageRecipients()): TraceGuzzleMiddleware
    {
        return new TraceGuzzleMiddleware($this->holder, new CallerName('orders', 'api'), $recipients, $this->reservation);
    }

    private function lastRequest(): RequestInterface
    {
        $request = $this->transport->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }

    /**
     * The headers alone cannot tell: a second middleware keeps the headers the first one set.
     *
     * @param HandlerStack<covariant GuzzleHandler> $stack
     */
    private function tracingMiddlewareCount(HandlerStack $stack): int
    {
        $entries = (new ReflectionProperty(HandlerStack::class, 'stack'))->getValue($stack);
        self::assertIsArray($entries);

        return count(array_filter($entries, static fn (mixed $entry): bool => is_array($entry) && ($entry[1] ?? null) === TraceGuzzleMiddleware::MIDDLEWARE_NAME));
    }

    private function continueReceivedTrace(): void
    {
        $received = TraceParent::fromHeader(self::RECEIVED);
        self::assertInstanceOf(TraceParent::class, $received);

        $this->holder->replace($this->holder->current()->continuing($received, TraceState::fromHeaders(['rojo=1']), Baggage::empty()));
    }
}
