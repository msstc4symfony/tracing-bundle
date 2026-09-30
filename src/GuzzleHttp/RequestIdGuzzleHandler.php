<?php

declare(strict_types=1);

namespace Hot\TracingBundle\GuzzleHttp;

use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use Hot\TracingBundle\EventListener\HTTPRequestListener;
use Hot\TracingBundle\Storage\RequestIdServiceInterface;
use Psr\Http\Message\RequestInterface;

final readonly class RequestIdGuzzleHandler
{
    public function __construct(
        private RequestIdServiceInterface $requestIdService,
    ) {
    }

    public function addHandler(ClientInterface $client): void
    {
        /** @var HandlerStack $stack */
        $stack = $client->getConfig('handler');
        $stack->push($this->generateHandler(), HTTPRequestListener::REQUEST_ID_HEADER);
    }

    private function generateHandler(): callable
    {
        $requestIdService = $this->requestIdService;

        return static fn (callable $handler): Closure => static function (RequestInterface $request, array $options) use ($handler, $requestIdService) {
            if (!$request->hasHeader(HTTPRequestListener::REQUEST_ID_HEADER)) {
                $request = $request->withHeader(
                    HTTPRequestListener::REQUEST_ID_HEADER,
                    $requestIdService->getRequestId(),
                )->withHeader(
                    HTTPRequestListener::REQUEST_FROM_HEADER,
                    $requestIdService->getCurrentRequestFrom(),
                );
            }

            return $handler($request, $options);
        };
    }
}
