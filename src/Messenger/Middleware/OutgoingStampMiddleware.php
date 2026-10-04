<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Messenger\Middleware;

use Msstc4Symfony\TracingBundle\Messenger\Stamp\TraceStamp;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Stamps a dispatched message with the current trace so its consumer continues it.
 */
final readonly class OutgoingStampMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestIdServiceInterface $requestIdService,
    ) {
    }

    #[Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (
            !$envelope->last(ReceivedStamp::class) instanceof ReceivedStamp
            && !$envelope->last(TraceStamp::class) instanceof TraceStamp
        ) {
            $envelope = $envelope->with(new TraceStamp(
                $this->requestIdService->getRequestId(),
                $this->requestIdService->getCurrentRequestFrom(),
                $this->requestIdService->createOutgoingTraceParent()->toHeader(),
                $this->requestIdService->getTraceState()?->value,
            ));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
