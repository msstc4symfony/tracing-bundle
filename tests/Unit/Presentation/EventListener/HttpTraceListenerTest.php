<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Presentation\EventListener;

use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use Msstc4Symfony\TracingBundle\Presentation\EventListener\HttpTraceListener;
use Override;
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

#[CoversClass(HttpTraceListener::class)]
#[UsesClass(InMemoryTraceContextHolder::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
#[UsesClass(Baggage::class)]
final class HttpTraceListenerTest extends TestCase
{
    private const TRACE_PARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    private InMemoryTraceContextHolder $holder;

    private HttpTraceListener $listener;

    #[Override]
    protected function setUp(): void
    {
        $this->holder = new InMemoryTraceContextHolder();
        $this->listener = new HttpTraceListener($this->holder);
    }

    public function testContinuesAValidTraceParentWithItsStateAndBaggage(): void
    {
        $runtimeId = $this->holder->current()->runtimeId;

        $this->listener->onRequest($this->requestEvent([
            'HTTP_TRACEPARENT' => self::TRACE_PARENT,
            'HTTP_TRACESTATE' => 'vendor=value',
            'HTTP_BAGGAGE' => 'msstc4symfony.caller=gateway%3Aweb,user=alice',
        ]));

        $context = $this->holder->current();
        self::assertSame($runtimeId, $context->runtimeId);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $context->traceParent->traceId);
        self::assertSame('b7ad6b7169203331', $context->parentSpanId);
        self::assertSame(self::TRACE_PARENT, $context->remoteTraceParent?->toHeader());
        self::assertSame('vendor=value', $context->traceState?->value);
        self::assertSame('gateway:web', $context->caller());
        self::assertSame('alice', $context->baggage->get('user'));
    }

    public function testKeepsBaggageWithoutAValidTraceParent(): void
    {
        $before = $this->holder->current();
        $request = new Request(server: ['HTTP_TRACESTATE' => 'vendor=value', 'HTTP_BAGGAGE' => 'user=alice']);
        // The spec treats a repeated traceparent as invalid.
        $request->headers->set('traceparent', [self::TRACE_PARENT, self::TRACE_PARENT]);

        $this->listener->onRequest(new RequestEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        $context = $this->holder->current();
        self::assertSame($before->traceParent, $context->traceParent);
        self::assertNull($context->remoteTraceParent);
        self::assertNull($context->traceState);
        self::assertNull($context->parentSpanId);
        self::assertSame('alice', $context->baggage->get('user'));
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

        self::assertNull($this->holder->current()->remoteTraceParent);
        self::assertNull($this->holder->current()->traceState);
    }

    public function testKeepsTheContextLoggedBeforeTheListener(): void
    {
        $before = $this->holder->current();

        $this->listener->onRequest($this->requestEvent([]));

        self::assertSame($before->runtimeId, $this->holder->current()->runtimeId);
        self::assertSame($before->traceParent, $this->holder->current()->traceParent);
    }

    public function testKeepsTheEarlyContextAfterAKernelReset(): void
    {
        $this->listener->onRequest($this->requestEvent([]));
        // kernel.reset resets every tagged service, the listener included.
        $this->holder->reset();
        $this->listener->reset();
        $before = $this->holder->current();

        $this->listener->onRequest($this->requestEvent([]));

        self::assertSame($before->runtimeId, $this->holder->current()->runtimeId);
    }

    public function testDoesNotInheritAUnitThatRanBetweenRequestsWithoutAKernelReset(): void
    {
        $this->listener->onRequest($this->requestEvent([]));
        // An in-process console command or a received message opens its own unit.
        $this->holder->reset();
        $between = $this->holder->current();

        $this->listener->onRequest($this->requestEvent([]));

        self::assertNotSame($between->runtimeId, $this->holder->current()->runtimeId);
        self::assertNotSame($between->traceParent->traceId, $this->holder->current()->traceParent->traceId);
    }

    public function testNextRequestDoesNotInheritTheTrace(): void
    {
        $this->listener->onRequest($this->requestEvent(['HTTP_TRACEPARENT' => self::TRACE_PARENT, 'HTTP_TRACESTATE' => 'rojo=1', 'HTTP_BAGGAGE' => 'user=alice']));

        $this->listener->onRequest($this->requestEvent([]));

        $context = $this->holder->current();
        self::assertNull($context->remoteTraceParent);
        self::assertNull($context->traceState);
        self::assertTrue($context->baggage->isEmpty());
        self::assertNotSame('0af7651916cd43dd8448eb211c80319c', $context->traceParent->traceId);
    }

    public function testRunsBeforeTheFrameworkRequestListeners(): void
    {
        $request = HttpTraceListener::getSubscribedEvents()[KernelEvents::REQUEST];
        self::assertIsArray($request);

        // ValidateRequestListener (256) is the highest FrameworkBundle request listener.
        self::assertGreaterThan(256, $request[1]);
    }

    public function testSubRequestKeepsTheMainTrace(): void
    {
        $before = $this->holder->current();

        $this->listener->onRequest($this->requestEvent(['HTTP_TRACEPARENT' => self::TRACE_PARENT], HttpKernelInterface::SUB_REQUEST));

        self::assertSame($before, $this->holder->current());
    }

    public function testReturnsTheServerSpanAsTraceResponse(): void
    {
        $response = new Response();

        $this->listener->onResponse($this->responseEvent($response, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame($this->holder->current()->traceParent->toHeader(), $response->headers->get('traceresponse'));
        self::assertFalse($response->headers->has('request-id'));
    }

    public function testKeepsATraceResponseTheApplicationSet(): void
    {
        $response = new Response(headers: ['traceresponse' => 'app-value']);

        $this->listener->onResponse($this->responseEvent($response, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame('app-value', $response->headers->get('traceresponse'));
    }

    public function testSubResponseIsLeftAlone(): void
    {
        $response = new Response();

        $this->listener->onResponse($this->responseEvent($response, HttpKernelInterface::SUB_REQUEST));

        self::assertFalse($response->headers->has('traceresponse'));
    }

    public function testListensToTheMainKernelEventsAtFixedPriorities(): void
    {
        self::assertSame(
            [KernelEvents::REQUEST => ['onRequest', 2048], KernelEvents::RESPONSE => ['onResponse', 100]],
            HttpTraceListener::getSubscribedEvents(),
        );
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
