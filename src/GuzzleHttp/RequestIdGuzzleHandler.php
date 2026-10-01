<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\GuzzleHttp;

use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Msstc4Symfony\TracingBundle\EventListener\HTTPRequestListener;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Service configurator for Guzzle clients: pushes a middleware adding the trace headers onto
 * the client's own handler stack, so the client keeps its concrete type for autowiring.
 */
final readonly class RequestIdGuzzleHandler
{
    public const string MIDDLEWARE_NAME = 'msstc4symfony_tracing';

    public function __construct(
        private RequestIdServiceInterface $requestIdService,
    ) {
    }

    public function addHandler(ClientInterface $client): void
    {
        $stack = $client->getConfig('handler');

        // A client built with a bare callable handler has no stack to extend.
        if (!$stack instanceof HandlerStack) {
            return;
        }

        $stack->remove(self::MIDDLEWARE_NAME);
        $stack->push($this->middleware(), self::MIDDLEWARE_NAME);
    }

    /**
     * @return Closure(callable(RequestInterface, array<mixed>): PromiseInterface): (Closure(RequestInterface, array<mixed>): PromiseInterface)
     */
    private function middleware(): Closure
    {
        $requestIdService = $this->requestIdService;

        return static fn (callable $handler): Closure => static function (RequestInterface $request, array $options) use ($handler, $requestIdService): PromiseInterface {
            if (!$request->hasHeader(HTTPRequestListener::REQUEST_ID_HEADER)) {
                $request = $request
                    ->withHeader(HTTPRequestListener::REQUEST_ID_HEADER, $requestIdService->getRequestId())
                    ->withHeader(HTTPRequestListener::REQUEST_FROM_HEADER, $requestIdService->getCurrentRequestFrom())
                ;
            }

            return $handler($request, $options);
        };
    }
}
