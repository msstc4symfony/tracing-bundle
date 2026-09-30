<?php

declare(strict_types=1);

namespace Hot\TracingBundle\Messenger\Middleware;

use Hot\TracingBundle\Messenger\Stamp\RequestIdStamp;
use Hot\TracingBundle\Storage\RequestIdServiceInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

class IncomingStampMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestIdServiceInterface $requestIdService,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $requestIdStamp = $envelope->last(RequestIdStamp::class);

        if ($requestIdStamp instanceof StampInterface) {
            $this->requestIdService
                ->setRequestId($requestIdStamp->requestId)
                ->setRequestFrom($requestIdStamp->requestFrom)
            ;
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
