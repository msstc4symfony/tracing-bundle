<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Messenger\Middleware;

use Msstc4Symfony\TracingBundle\Messenger\Stamp\RequestIdStamp;
use Msstc4Symfony\TracingBundle\Messenger\Stamp\TraceContextStamp;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\W3cTraceContextInterface;
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
        private ?W3cTraceContextInterface $w3cTraceContext = null,
    ) {
    }

    #[Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(ReceivedStamp::class) instanceof ReceivedStamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        if (!$envelope->last(RequestIdStamp::class) instanceof RequestIdStamp) {
            $envelope = $envelope->with(new RequestIdStamp(
                $this->requestIdService->getRequestId(),
                $this->requestIdService->getCurrentRequestFrom(),
            ));
        }

        if ($this->w3cTraceContext instanceof W3cTraceContextInterface && !$envelope->last(TraceContextStamp::class) instanceof TraceContextStamp) {
            $envelope = $envelope->with(new TraceContextStamp(
                $this->w3cTraceContext->createOutgoingTraceParent()->toHeader(),
                $this->w3cTraceContext->getTraceState()?->value,
            ));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
