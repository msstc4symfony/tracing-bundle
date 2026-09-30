<?php

declare(strict_types=1);

namespace Hot\TracingBundle\Messenger\Middleware;

use Hot\TracingBundle\Messenger\Stamp\RequestIdStamp;
use Hot\TracingBundle\Storage\RequestIdServiceInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

class OutgoingStampMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestIdServiceInterface $requestIdService,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Добавляем X-Request-Id из окружения, если есть
        $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));

        // Добавляем штамп (если его нет)
        if (!$envelope->last(RequestIdStamp::class) instanceof RequestIdStamp) {
            $envelope = $envelope->with(
                new RequestIdStamp($this->requestIdService->getRequestId(), $this->requestIdService->getRequestFrom()),
            );
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
