<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\EventListener;

use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

final class HTTPRequestListener implements EventSubscriberInterface, ResetInterface
{
    public const string REQUEST_ID_HEADER = 'request-id';

    public const string REQUEST_FROM_HEADER = 'request-from';

    public const string TRACEPARENT_HEADER = 'traceparent';

    public const string TRACESTATE_HEADER = 'tracestate';

    /**
     * Whether kernel.reset (or a fresh container) opened the current unit after the previous main
     * request: records logged before this listener then already carry the unit's ids.
     */
    private bool $unitOpenedByReset = true;

    public function __construct(
        private readonly RequestIdServiceInterface $requestIdService,
    ) {
    }

    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            // Ahead of FrameworkBundle's request listeners (ValidateRequestListener is 256), so their logs carry the incoming trace.
            KernelEvents::REQUEST => ['onRequest', 2048],
            KernelEvents::RESPONSE => ['onResponse', 100],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        // Sub-requests (fragments, forwards) belong to the main request's trace.
        if (!$event->isMainRequest()) {
            return;
        }

        // Not a runtime-id comparison: a console command or message handled in-process between
        // requests also changes the runtime id, and its unit must not leak into this request.
        if (!$this->unitOpenedByReset) {
            $this->requestIdService->reset();
        }
        $this->unitOpenedByReset = false;

        $headers = $event->getRequest()->headers;
        $traceParent = $this->continueW3cTrace($headers);

        $requestId = $headers->get(self::REQUEST_ID_HEADER);
        if ($requestId === null || $requestId === '') {
            // An OpenTelemetry caller sends no request-id; reusing its trace id keeps both views of the trace joined.
            if (!$traceParent instanceof TraceParent) {
                return;
            }

            $requestId = $traceParent->toRequestId();
        }

        $this->requestIdService
            ->setRequestId($requestId)
            ->setRequestFrom($headers->get(self::REQUEST_FROM_HEADER) ?? RequestIdServiceInterface::UNKNOWN_APPLICATION)
        ;
    }

    private function continueW3cTrace(HeaderBag $headers): ?TraceParent
    {
        // The spec treats a repeated traceparent as invalid.
        $values = $headers->all(self::TRACEPARENT_HEADER);
        $traceParent = count($values) === 1 && is_string($values[0]) ? TraceParent::fromHeader($values[0]) : null;
        if (!$traceParent instanceof TraceParent) {
            return null;
        }

        $this->requestIdService->continueTrace($traceParent, TraceState::fromHeaders($headers->all(self::TRACESTATE_HEADER)));

        return $traceParent;
    }

    #[Override]
    public function reset(): void
    {
        $this->unitOpenedByReset = true;
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set(self::REQUEST_ID_HEADER, $this->requestIdService->getRequestId());
        $headers->set(self::REQUEST_FROM_HEADER, $this->requestIdService->getCurrentRequestFrom());
    }
}
