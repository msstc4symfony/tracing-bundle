<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Presentation\EventListener;

use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal
 */
final class HttpTraceListener implements EventSubscriberInterface, ResetInterface
{
    /**
     * Whether kernel.reset (or a fresh container) opened the current unit after the previous main
     * request: records logged before this listener then already carry the unit's ids.
     */
    private bool $unitOpenedByReset = true;

    public function __construct(
        private readonly TraceContextHolderInterface $holder,
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
            $this->holder->reset();
        }
        $this->unitOpenedByReset = false;

        $headers = $event->getRequest()->headers;
        // Baggage does not depend on traceparent: it is kept even when the trace starts here.
        $baggage = Baggage::fromHeaders($headers->all(Baggage::HEADER));
        $received = $this->receivedTraceParent($headers);
        $context = $this->holder->current();

        $this->holder->replace($received instanceof TraceParent
            ? $context->continuing($received, TraceState::fromHeaders($headers->all(TraceState::HEADER)), $baggage)
            : $context->withBaggage($baggage));
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        if (!$headers->has(TraceParent::RESPONSE_HEADER)) {
            $headers->set(TraceParent::RESPONSE_HEADER, $this->holder->current()->traceParent->toHeader());
        }
    }

    #[Override]
    public function reset(): void
    {
        $this->unitOpenedByReset = true;
    }

    private function receivedTraceParent(HeaderBag $headers): ?TraceParent
    {
        // The spec treats a repeated traceparent as invalid.
        $values = $headers->all(TraceParent::HEADER);

        return count($values) === 1 && is_string($values[0]) ? TraceParent::fromHeader($values[0]) : null;
    }
}
