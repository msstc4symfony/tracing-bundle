<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\EventListener;

use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use Msstc4Symfony\TracingBundle\Storage\W3cTraceContextInterface;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class HTTPRequestListener implements EventSubscriberInterface
{
    public const string REQUEST_ID_HEADER = 'request-id';

    public const string REQUEST_FROM_HEADER = 'request-from';

    public const string TRACEPARENT_HEADER = 'traceparent';

    public const string TRACESTATE_HEADER = 'tracestate';

    public function __construct(
        private RequestIdServiceInterface $requestIdService,
        private ?W3cTraceContextInterface $w3cTraceContext = null,
    ) {
    }

    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 100],
            KernelEvents::RESPONSE => ['onResponse', 100],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        // Sub-requests (fragments, forwards) belong to the main request's trace.
        if (!$event->isMainRequest()) {
            return;
        }

        $this->requestIdService->reset();

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
        if (!$this->w3cTraceContext instanceof W3cTraceContextInterface) {
            return null;
        }

        // The spec treats a repeated traceparent as invalid.
        $values = $headers->all(self::TRACEPARENT_HEADER);
        $traceParent = count($values) === 1 && is_string($values[0]) ? TraceParent::fromHeader($values[0]) : null;
        if (!$traceParent instanceof TraceParent) {
            return null;
        }

        $this->w3cTraceContext->continueTrace($traceParent, TraceState::fromHeaders($headers->all(self::TRACESTATE_HEADER)));

        return $traceParent;
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
