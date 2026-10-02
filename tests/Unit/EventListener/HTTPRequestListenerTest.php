<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\EventListener;

use Msstc4Symfony\TracingBundle\EventListener\HTTPRequestListener;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(HTTPRequestListener::class)]
#[UsesClass(RequestIdService::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
final class HTTPRequestListenerTest extends TestCase
{
    private const string TRACE_PARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    private RequestIdService $storage;

    private HTTPRequestListener $listener;

    protected function setUp(): void
    {
        $this->storage = new RequestIdService('shop', 'api');
        $this->listener = new HTTPRequestListener($this->storage, $this->storage);
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
        $this->listener->onRequest($this->requestEvent(['HTTP_REQUEST_ID' => 'previous-request']));
        $runtimeId = $this->storage->getRuntimeId();

        $this->listener->onRequest($this->requestEvent([]));

        self::assertNotSame('previous-request', $this->storage->getRequestId());
        self::assertNotSame($runtimeId, $this->storage->getRuntimeId());
    }

    public function testKeepsTheIdsLoggedBeforeTheListener(): void
    {
        $runtimeId = $this->storage->getRuntimeId();
        $requestId = $this->storage->getRequestId();
        $traceId = $this->storage->getTraceParent()->traceId;

        $this->listener->onRequest($this->requestEvent([]));

        self::assertSame($runtimeId, $this->storage->getRuntimeId());
        self::assertSame($requestId, $this->storage->getRequestId());
        self::assertSame($traceId, $this->storage->getTraceParent()->traceId);
    }

    public function testKeepsTheEarlyRuntimeIdWhenTheCallerSendsItsTrace(): void
    {
        $runtimeId = $this->storage->getRuntimeId();
        $this->storage->getRequestId();

        $this->listener->onRequest($this->requestEvent(['HTTP_REQUEST_ID' => 'abc', 'HTTP_TRACEPARENT' => self::TRACE_PARENT]));

        self::assertSame($runtimeId, $this->storage->getRuntimeId());
        self::assertSame('abc', $this->storage->getRequestId());
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $this->storage->getTraceParent()->traceId);
    }

    public function testKeepsTheEarlyIdsAfterAKernelReset(): void
    {
        $this->listener->onRequest($this->requestEvent(['HTTP_REQUEST_ID' => 'previous-request']));
        $this->storage->reset();
        $requestId = $this->storage->getRequestId();

        $this->listener->onRequest($this->requestEvent([]));

        self::assertSame($requestId, $this->storage->getRequestId());
    }

    public function testRunsBeforeTheFrameworkRequestListeners(): void
    {
        $request = HTTPRequestListener::getSubscribedEvents()[KernelEvents::REQUEST];
        self::assertIsArray($request);

        // ValidateRequestListener (256) is the highest FrameworkBundle request listener.
        self::assertGreaterThan(256, $request[1]);
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

    public function testKeepsAValidTraceParentNextToTheRequestId(): void
    {
        $this->listener->onRequest($this->requestEvent(['HTTP_REQUEST_ID' => 'abc', 'HTTP_TRACEPARENT' => self::TRACE_PARENT]));

        self::assertSame(self::TRACE_PARENT, $this->storage->getRemoteTraceParent()?->toHeader());
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $this->storage->getTraceParent()->traceId);
        self::assertSame('abc', $this->storage->getRequestId());
    }

    public function testDerivesTheRequestIdFromTheTraceIdWhenNoneIsSent(): void
    {
        $this->listener->onRequest($this->requestEvent(['HTTP_TRACEPARENT' => self::TRACE_PARENT]));

        self::assertSame('4bf92f35-77b3-4da6-a3ce-929d0e0e4736', $this->storage->getRequestId());
        self::assertSame('unknown', $this->storage->getRequestFrom());
    }

    public function testDerivedRequestIdKeepsTheCaller(): void
    {
        $this->listener->onRequest($this->requestEvent(['HTTP_TRACEPARENT' => self::TRACE_PARENT, 'HTTP_REQUEST_FROM' => 'billing:api']));

        self::assertSame('billing:api', $this->storage->getRequestFrom());
    }

    public function testKeepsTheTraceStateOfAValidTraceParent(): void
    {
        $this->listener->onRequest($this->requestEvent(['HTTP_TRACEPARENT' => self::TRACE_PARENT, 'HTTP_TRACESTATE' => 'rojo=00f067aa0ba902b7']));

        self::assertSame('rojo=00f067aa0ba902b7', $this->storage->getTraceState()?->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidTraceParents(): iterable
    {
        yield 'version ff' => ['ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'];
        yield 'all-zero trace id' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'];
        yield 'garbage' => ['not-a-traceparent'];
    }

    #[DataProvider('provideInvalidTraceParents')]
    public function testIgnoresAnInvalidTraceParentAndItsTraceState(string $traceParent): void
    {
        $this->listener->onRequest($this->requestEvent(['HTTP_TRACEPARENT' => $traceParent, 'HTTP_TRACESTATE' => 'rojo=1']));

        self::assertNull($this->storage->getRemoteTraceParent());
        self::assertNull($this->storage->getTraceState());
        self::assertSame('shop:api', $this->storage->getRequestFrom());
    }

    public function testIgnoresRepeatedTraceParentHeaders(): void
    {
        $request = new Request();
        $request->headers->set('traceparent', [self::TRACE_PARENT, '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']);

        $this->listener->onRequest(new RequestEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertNull($this->storage->getRemoteTraceParent());
    }

    public function testIgnoresTheTraceParentWhenW3cIsDisabled(): void
    {
        new HTTPRequestListener($this->storage)->onRequest($this->requestEvent(['HTTP_TRACEPARENT' => self::TRACE_PARENT]));

        self::assertNull($this->storage->getRemoteTraceParent());
        self::assertNotSame('4bf92f35-77b3-4da6-a3ce-929d0e0e4736', $this->storage->getRequestId());
    }

    public function testNextRequestDoesNotInheritTheTrace(): void
    {
        $this->listener->onRequest($this->requestEvent(['HTTP_TRACEPARENT' => self::TRACE_PARENT, 'HTTP_TRACESTATE' => 'rojo=1']));

        $this->listener->onRequest($this->requestEvent([]));

        self::assertNull($this->storage->getRemoteTraceParent());
        self::assertNull($this->storage->getTraceState());
        self::assertNotSame('4bf92f3577b34da6a3ce929d0e0e4736', $this->storage->getTraceParent()->traceId);
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
