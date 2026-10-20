<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Framework\Messenger;

use Msstc4Symfony\TracingBundle\Application\CallerName;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Stamps a dispatched message with the current trace so its consumer continues it.
 */
final readonly class OutgoingTraceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TraceContextHolderInterface $holder,
        private CallerName $caller,
    ) {
    }

    #[Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (
            !$envelope->last(ReceivedStamp::class) instanceof ReceivedStamp
            && !$envelope->last(TraceStamp::class) instanceof TraceStamp
        ) {
            $trace = $this->holder->current()->outgoing($this->caller->value);
            $envelope = $envelope->with(new TraceStamp(
                $trace->traceParent->toHeader(),
                $trace->traceState?->value,
                $trace->baggage->toHeader(),
            ));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
