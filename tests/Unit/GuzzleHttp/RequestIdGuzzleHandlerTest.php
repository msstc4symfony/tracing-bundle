<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\GuzzleHttp;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Msstc4Symfony\TracingBundle\GuzzleHttp\RequestIdGuzzleHandler;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

#[CoversClass(RequestIdGuzzleHandler::class)]
#[UsesClass(RequestIdService::class)]
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
}
