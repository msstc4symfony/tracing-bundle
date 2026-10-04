<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Messenger\Middleware;

use Msstc4Symfony\TracingBundle\Messenger\Stamp\TraceStamp;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Runs a consumed message in its sender's trace.
 *
 * A message consumed at the top of a worker starts a unit of work: the trace is replaced and
 * deliberately kept after handling, so the worker's ack/failure logs and messages released by
 * dispatch_after_current_bus stay in it; WorkerTraceSubscriber closes the unit afterwards.
 * A message received inside another unit (sync:// transport) restores the outer trace once
 * handled.
 */
final class IncomingStampMiddleware implements MiddlewareInterface
{
    private int $depth = 0;

    private bool $unitOpen = false;

    public function __construct(
        private readonly RequestIdServiceInterface $requestIdService,
    ) {
    }

    #[Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class) instanceof ReceivedStamp;
        $outer = $received && $this->depth > 0 ? $this->outerTrace() : null;

        if ($received) {
            $this->enter($envelope, $outer);
        }

        $this->depth++;

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->depth--;

            if ($outer instanceof TraceContext) {
                $this->requestIdService->restore($outer);
            }
        }
    }

    /**
     * Clears the trace of the last top-level consumed message; a no-op otherwise, so idle
     * worker ticks keep the command's trace.
     */
    public function closeUnit(): void
    {
        if (!$this->unitOpen || $this->depth > 0) {
            return;
        }

        $this->unitOpen = false;
        $this->requestIdService->reset();
    }

    private function outerTrace(): TraceContext
    {
        // Started now, so a nested unit and the rest of the outer one share the same trace and request id.
        $this->requestIdService->getTraceParent();
        $this->requestIdService->getRequestId();

        return $this->requestIdService->snapshot();
    }

    private function enter(Envelope $envelope, ?TraceContext $outer): void
    {
        $this->requestIdService->reset();
        $this->unitOpen = $this->unitOpen || $this->depth === 0;

        $stamp = $envelope->last(TraceStamp::class);
        if ($stamp instanceof TraceStamp) {
            $this->continueStampedTrace($stamp);
            $this->requestIdService
                ->setRequestId($stamp->requestId)
                ->setRequestFrom($stamp->requestFrom)
            ;

            return;
        }

        if ($outer instanceof TraceContext && $outer->traceParent instanceof TraceParent && $outer->requestId !== null && $outer->requestFrom !== null) {
            // Unstamped sync:// message: stays in the dispatching unit's request, as a child span.
            $this->requestIdService
                ->continueTrace($outer->traceParent, $outer->traceState)
                ->setRequestId($outer->requestId)
                ->setRequestFrom($outer->requestFrom)
            ;

            return;
        }

        // Keeps the request id and the W3C trace joined when the sender stamped nothing.
        $this->requestIdService->setRequestId($this->requestIdService->getTraceParent()->toRequestId());
    }

    private function continueStampedTrace(TraceStamp $stamp): void
    {
        $traceParent = TraceParent::fromHeader($stamp->traceParent);
        if ($traceParent instanceof TraceParent) {
            $this->requestIdService->continueTrace($traceParent, TraceState::fromHeaders([$stamp->traceState]));
        }
    }
}
