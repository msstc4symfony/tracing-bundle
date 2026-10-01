<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Messenger\Middleware;

use Msstc4Symfony\TracingBundle\Messenger\Stamp\RequestIdStamp;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
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
 * dispatch_after_current_bus stay in it; ResetTraceOnWorkerRunning clears it before the next
 * message. A message received inside another unit (sync:// transport) restores the outer trace
 * once handled.
 */
final class IncomingStampMiddleware implements MiddlewareInterface
{
    private int $depth = 0;

    public function __construct(
        private readonly RequestIdServiceInterface $requestIdService,
    ) {
    }

    #[Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class) instanceof ReceivedStamp;
        $outer = $received && $this->depth > 0 ? $this->requestIdService->snapshot() : null;

        if ($received) {
            $this->enter($envelope);
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

    private function enter(Envelope $envelope): void
    {
        $this->requestIdService->reset();

        $stamp = $envelope->last(RequestIdStamp::class);
        if ($stamp instanceof RequestIdStamp) {
            $this->requestIdService
                ->setRequestId($stamp->requestId)
                ->setRequestFrom($stamp->requestFrom)
            ;
        }
    }
}
