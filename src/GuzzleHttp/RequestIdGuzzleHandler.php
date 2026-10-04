<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\GuzzleHttp;

use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use LogicException;
use Msstc4Symfony\TracingBundle\EventListener\HTTPRequestListener;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use Psr\Http\Message\RequestInterface;

/**
 * Adds the trace headers to Guzzle requests through a handler-stack middleware.
 *
 * `GuzzlePass` wires it into container clients: through the client's `handler` config
 * (`decorateHandler()`), or, for clients it cannot build itself, as a service configurator
 * (`addHandler()`). Either way the client keeps its concrete type for autowiring.
 *
 * Request options are keyed `array-key`, as Guzzle's own `HandlerStack` types them.
 *
 * @phpstan-type GuzzleOptions array<array-key, mixed>
 * @phpstan-type GuzzleHandler callable(RequestInterface, GuzzleOptions): PromiseInterface
 */
final readonly class RequestIdGuzzleHandler
{
    public const string MIDDLEWARE_NAME = 'msstc4symfony_tracing';

    public function __construct(
        private RequestIdServiceInterface $requestIdService,
    ) {
    }

    /**
     * Returns the handler a client should be created with: the given stack (or Guzzle's default
     * one) with the tracing middleware. A bare handler is returned unchanged, so a client built
     * on it still exposes the handler it was given.
     *
     * @param GuzzleHandler|null $handler
     *
     * @return GuzzleHandler
     */
    public function decorateHandler(?callable $handler = null): callable
    {
        if ($handler !== null && !$handler instanceof HandlerStack) {
            return $handler;
        }

        $stack = $handler ?? HandlerStack::create();
        $stack->remove(self::MIDDLEWARE_NAME);
        $stack->push($this->middleware(), self::MIDDLEWARE_NAME);

        return $stack;
    }

    /**
     * Service configurator for clients that expose their handler through `getConfig()` (every
     * Guzzle 7 client, `GuzzleHttp\Client` on Guzzle 8).
     *
     * @throws LogicException when the client has no `getConfig()` (a Guzzle 8 `ClientInterface`)
     */
    public function addHandler(ClientInterface $client): void
    {
        if (!method_exists($client, 'getConfig')) {
            throw new LogicException(sprintf('%s has no getConfig(): create it with the handler returned by %s::decorateHandler().', $client::class, self::class));
        }

        $stack = $client->getConfig('handler');

        // A client built with a bare callable handler has no stack to extend.
        if ($stack instanceof HandlerStack) {
            $this->decorateHandler($stack);
        }
    }

    /**
     * The middleware itself, for stacks built by hand: `$stack->push($handler->middleware())`.
     *
     * @return Closure(GuzzleHandler): (Closure(RequestInterface, GuzzleOptions): PromiseInterface)
     */
    public function middleware(): Closure
    {
        return $this->wrap(...);
    }

    /**
     * @param GuzzleHandler $handler
     *
     * @return Closure(RequestInterface, GuzzleOptions): PromiseInterface
     */
    private function wrap(callable $handler): Closure
    {
        return fn (RequestInterface $request, array $options): PromiseInterface => $handler($this->withTraceHeaders($request), $options);
    }

    private function withTraceHeaders(RequestInterface $request): RequestInterface
    {
        if (!$request->hasHeader(HTTPRequestListener::REQUEST_ID_HEADER)) {
            $request = $request->withHeader(HTTPRequestListener::REQUEST_ID_HEADER, $this->requestIdService->getRequestId());
        }

        if (!$request->hasHeader(HTTPRequestListener::REQUEST_FROM_HEADER)) {
            $request = $request->withHeader(HTTPRequestListener::REQUEST_FROM_HEADER, $this->requestIdService->getCurrentRequestFrom());
        }

        if ($request->hasHeader(HTTPRequestListener::TRACEPARENT_HEADER)) {
            return $request;
        }

        $request = $request->withHeader(HTTPRequestListener::TRACEPARENT_HEADER, $this->requestIdService->createOutgoingTraceParent()->toHeader());

        $state = $this->requestIdService->getTraceState();
        if ($state instanceof TraceState && !$request->hasHeader(HTTPRequestListener::TRACESTATE_HEADER)) {
            return $request->withHeader(HTTPRequestListener::TRACESTATE_HEADER, $state->value);
        }

        return $request;
    }
}
