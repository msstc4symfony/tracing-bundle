<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Messenger\Middleware;

use Msstc4Symfony\TracingBundle\Messenger\Stamp\RequestIdStamp;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Restores the sender's trace for a message consumed from a transport, and drops it once the
 * message is handled so the worker's next message does not inherit it.
 */
final readonly class IncomingStampMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestIdServiceInterface $requestIdService,
    ) {
    }

    #[Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Synchronous dispatch runs inside the caller's trace; leave it untouched.
        if (!$envelope->last(ReceivedStamp::class) instanceof ReceivedStamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        $this->requestIdService->reset();

        $stamp = $envelope->last(RequestIdStamp::class);
        if ($stamp instanceof RequestIdStamp) {
            $this->requestIdService
                ->setRequestId($stamp->requestId)
                ->setRequestFrom($stamp->requestFrom)
            ;
        }

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->requestIdService->reset();
        }
    }
}
