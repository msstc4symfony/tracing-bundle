<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\GuzzleHttp;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Msstc4Symfony\TracingBundle\GuzzleHttp\RequestIdGuzzleHandler;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

#[CoversClass(RequestIdGuzzleHandler::class)]
#[UsesClass(RequestIdService::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
final class RequestIdGuzzleHandlerTest extends TestCase
{
    private MockHandler $transport;

    private Client $client;

    protected function setUp(): void
    {
        if (!class_exists(Client::class)) {
            self::markTestSkipped('guzzlehttp/guzzle is not installed');
        }

        $this->transport = new MockHandler([new Response(), new Response()]);
        $this->client = new Client(['handler' => HandlerStack::create($this->transport)]);
    }

    public function testAddsTheTraceHeadersOnce(): void
    {
        $handler = new RequestIdGuzzleHandler(new RequestIdService('shop', 'api')->setRequestId('abc'));
        $handler->addHandler($this->client);
        $handler->addHandler($this->client);

        $this->client->request('GET', 'https://example.com');

        $request = $this->transport->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(['abc'], $request->getHeader('request-id'));
        self::assertSame(['shop:api'], $request->getHeader('request-from'));
    }

    public function testKeepsAHeaderTheCallerSet(): void
    {
        new RequestIdGuzzleHandler(new RequestIdService('shop', 'api'))->addHandler($this->client);

        $this->client->request('GET', 'https://example.com', ['headers' => ['request-id' => 'mine']]);

        self::assertSame(['mine'], $this->transport->getLastRequest()?->getHeader('request-id'));
    }

    public function testKeepsARequestFromTheCallerSet(): void
    {
        new RequestIdGuzzleHandler(new RequestIdService('shop', 'api')->setRequestId('abc'))->addHandler($this->client);

        $this->client->request('GET', 'https://example.com', ['headers' => ['request-from' => 'gateway:edge']]);

        $request = $this->transport->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(['abc'], $request->getHeader('request-id'));
        self::assertSame(['gateway:edge'], $request->getHeader('request-from'));
    }

    public function testIgnoresClientsWithoutAHandlerStack(): void
    {
        $client = new Client(['handler' => new MockHandler([new Response()])]);

        new RequestIdGuzzleHandler(new RequestIdService('shop', 'api'))->addHandler($client);

        self::assertNotInstanceOf(HandlerStack::class, $client->getConfig('handler'));
    }

    public function testSendsTheW3cTraceWithANewSpanPerRequest(): void
    {
        $storage = $this->continuedTrace();
        new RequestIdGuzzleHandler($storage, $storage)->addHandler($this->client);

        $this->client->request('GET', 'https://example.com');
        $first = TraceParent::fromHeader($this->transport->getLastRequest()?->getHeaderLine('traceparent') ?? '');
        $state = $this->transport->getLastRequest()?->getHeaderLine('tracestate');
        $this->client->request('GET', 'https://example.com');
        $second = TraceParent::fromHeader($this->transport->getLastRequest()?->getHeaderLine('traceparent') ?? '');

        self::assertInstanceOf(TraceParent::class, $first);
        self::assertInstanceOf(TraceParent::class, $second);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $first->traceId);
        self::assertSame(0, $first->flags);
        self::assertSame($first->traceId, $second->traceId);
        self::assertNotSame($first->parentId, $second->parentId);
        self::assertSame('rojo=1', $state);
    }

    public function testKeepsATraceParentTheCallerSet(): void
    {
        $storage = $this->continuedTrace();
        new RequestIdGuzzleHandler($storage, $storage)->addHandler($this->client);

        $this->client->request('GET', 'https://example.com', ['headers' => ['traceparent' => 'mine']]);

        $request = $this->transport->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(['mine'], $request->getHeader('traceparent'));
        self::assertFalse($request->hasHeader('tracestate'));
    }

    public function testKeepsATraceStateTheCallerSet(): void
    {
        $storage = $this->continuedTrace();
        new RequestIdGuzzleHandler($storage, $storage)->addHandler($this->client);

        $this->client->request('GET', 'https://example.com', ['headers' => ['tracestate' => 'mine=1']]);

        $request = $this->transport->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(['mine=1'], $request->getHeader('tracestate'));
        self::assertTrue($request->hasHeader('traceparent'));
    }

    public function testSendsNoW3cHeadersWhenDisabled(): void
    {
        new RequestIdGuzzleHandler($this->continuedTrace())->addHandler($this->client);

        $this->client->request('GET', 'https://example.com');

        self::assertFalse($this->transport->getLastRequest()?->hasHeader('traceparent'));
    }

    private function continuedTrace(): RequestIdService
    {
        $received = TraceParent::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00');
        self::assertInstanceOf(TraceParent::class, $received);

        return new RequestIdService('shop', 'api')->continueTrace($received, TraceState::fromHeaders(['rojo=1']));
    }
}
