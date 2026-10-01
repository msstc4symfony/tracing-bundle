<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\EventListener;

use Msstc4Symfony\TracingBundle\EventListener\HTTPRequestListener;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(HTTPRequestListener::class)]
final class HTTPRequestListenerTest extends TestCase
{
    private RequestIdService $storage;

    private HTTPRequestListener $listener;

    protected function setUp(): void
    {
        $this->storage = new RequestIdService('shop', 'api');
        $this->listener = new HTTPRequestListener($this->storage);
    }

    public function testAdoptsIncomingTraceHeaders(): void
    {
        $this->listener->onRequest($this->requestEvent(['HTTP_REQUEST_ID' => 'abc', 'HTTP_REQUEST_FROM' => 'billing:worker']));

        self::assertSame('abc', $this->storage->getRequestId());
        self::assertSame('billing:worker', $this->storage->getRequestFrom());
    }

    public function testCallerDefaultsToUnknownWhenOnlyTheIdIsSent(): void
    {
        $this->listener->onRequest($this->requestEvent(['HTTP_REQUEST_ID' => 'abc']));

        self::assertSame('unknown', $this->storage->getRequestFrom());
    }

    public function testStartsAFreshTraceWithoutHeaders(): void
    {
        $this->storage->setRequestId('left-over-from-previous-request');

        $this->listener->onRequest($this->requestEvent([]));

        self::assertNotSame('left-over-from-previous-request', $this->storage->getRequestId());
    }

    public function testSubRequestKeepsTheMainTrace(): void
    {
        $this->storage->setRequestId('main');

        $this->listener->onRequest($this->requestEvent(['HTTP_REQUEST_ID' => 'sub'], HttpKernelInterface::SUB_REQUEST));

        self::assertSame('main', $this->storage->getRequestId());
    }

    public function testResponseCarriesTheTrace(): void
    {
        $this->storage->setRequestId('abc');
        $response = new Response();

        $this->listener->onResponse($this->responseEvent($response, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame('abc', $response->headers->get(HTTPRequestListener::REQUEST_ID_HEADER));
        self::assertSame('shop:api', $response->headers->get(HTTPRequestListener::REQUEST_FROM_HEADER));
    }

    public function testSubResponseIsLeftAlone(): void
    {
        $response = new Response();

        $this->listener->onResponse($this->responseEvent($response, HttpKernelInterface::SUB_REQUEST));

        self::assertFalse($response->headers->has(HTTPRequestListener::REQUEST_ID_HEADER));
    }

    /**
     * @param array<string, string> $server
     */
    private function requestEvent(array $server, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent(self::createStub(HttpKernelInterface::class), new Request(server: $server), $type);
    }

    private function responseEvent(Response $response, int $type): ResponseEvent
    {
        return new ResponseEvent(self::createStub(HttpKernelInterface::class), new Request(), $type, $response);
    }
}
