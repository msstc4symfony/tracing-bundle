<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Messenger\Middleware;

use Msstc4Symfony\TracingBundle\Messenger\Stamp\RequestIdStamp;
use Msstc4Symfony\TracingBundle\Messenger\Stamp\TraceContextStamp;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use Msstc4Symfony\TracingBundle\Storage\W3cTraceContextInterface;
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
        private readonly ?W3cTraceContextInterface $w3cTraceContext = null,
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
        // Started now, so a nested unit and the rest of the outer one share the same trace.
        $this->w3cTraceContext?->getTraceParent();

        return $this->requestIdService->snapshot();
    }

    private function enter(Envelope $envelope, ?TraceContext $outer): void
    {
        $this->requestIdService->reset();
        $this->unitOpen = $this->unitOpen || $this->depth === 0;
        $traceParent = $this->continueW3cTrace($envelope, $outer);

        $stamp = $envelope->last(RequestIdStamp::class);
        if ($stamp instanceof RequestIdStamp) {
            $this->requestIdService
                ->setRequestId($stamp->requestId)
                ->setRequestFrom($stamp->requestFrom)
            ;
        } elseif ($traceParent instanceof TraceParent) {
            // Same rule as for HTTP: a sender without a request id still has a trace id.
            $this->requestIdService
                ->setRequestId($traceParent->toRequestId())
                ->setRequestFrom(RequestIdServiceInterface::UNKNOWN_APPLICATION)
            ;
        }
    }

    /**
     * @return TraceParent|null the stamp's valid traceparent
     */
    private function continueW3cTrace(Envelope $envelope, ?TraceContext $outer): ?TraceParent
    {
        if (!$this->w3cTraceContext instanceof W3cTraceContextInterface) {
            return null;
        }

        $stamp = $envelope->last(TraceContextStamp::class);
        if ($stamp instanceof TraceContextStamp) {
            $traceParent = TraceParent::fromHeader($stamp->traceParent);
            if ($traceParent instanceof TraceParent) {
                $this->w3cTraceContext->continueTrace($traceParent, TraceState::fromHeaders([$stamp->traceState]));

                return $traceParent;
            }
        }

        if ($outer?->traceParent instanceof TraceParent) {
            // Unstamped sync:// message (stamping is opt-in): a child span of the dispatching unit.
            $this->w3cTraceContext->continueTrace($outer->traceParent, $outer->traceState);
        }

        return null;
    }
}
