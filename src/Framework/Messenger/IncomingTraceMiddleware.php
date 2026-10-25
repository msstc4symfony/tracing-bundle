<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Framework\Messenger;

use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\SampleRatio;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
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
final class IncomingTraceMiddleware implements MiddlewareInterface
{
    private int $depth = 0;

    private bool $unitOpen = false;

    public function __construct(
        private readonly TraceContextHolderInterface $holder,
        private readonly SampleRatio $sampleRatio = new SampleRatio(1.0),
    ) {
    }

    #[Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class) instanceof ReceivedStamp;
        $outer = $received && $this->depth > 0 ? $this->holder->current() : null;

        if ($received) {
            $this->enter($envelope, $outer);
        }

        $this->depth++;

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->depth--;

            if ($outer instanceof TraceContext) {
                $this->holder->replace($outer);
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
        $this->holder->reset();
    }

    private function enter(Envelope $envelope, ?TraceContext $outer): void
    {
        $this->unitOpen = $this->unitOpen || $this->depth === 0;

        $stamp = $envelope->last(TraceStamp::class);
        if (!$stamp instanceof TraceStamp) {
            // Unstamped sync:// message: a child span of the dispatching unit.
            $this->holder->replace($outer instanceof TraceContext ? $outer->withChildSpan() : TraceContext::start($this->sampleRatio));

            return;
        }

        $baggage = Baggage::fromHeaders([$stamp->baggage]);
        $received = TraceParent::fromHeader($stamp->traceParent);

        $this->holder->replace($received instanceof TraceParent
            ? TraceContext::start($this->sampleRatio)->continuing($received, TraceState::fromHeaders([$stamp->traceState]), $baggage)
            : TraceContext::start($this->sampleRatio)->withBaggage($baggage));
    }
}
