<?php

declare(strict_types=1);

namespace Hot\TracingBundle\EventListener;

use Hot\TracingBundle\Storage\RequestIdServiceInterface;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class HTTPRequestListener implements EventSubscriberInterface
{
    public const string REQUEST_ID_HEADER = 'request-id';

    public const string REQUEST_FROM_HEADER = 'request-from';

    public function __construct(
        private RequestIdServiceInterface $requestIdService,
    ) {
    }

    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 100],
            KernelEvents::RESPONSE => ['onResponse', 100],
        ];
    }

    public function onRequest(KernelEvent $event): void
    {
        $this->requestIdService
            ->initRuntimeId()
            ->resetRequestData()
        ;

        $request = $event->getRequest();
        if (!$request->headers->has(self::REQUEST_ID_HEADER)) {
            return;
        }

        $requestId = $request->headers->get(self::REQUEST_ID_HEADER);
        $this->requestIdService->setRequestId($requestId ?? $this->requestIdService->generateRequestId());

        $requestFrom = $request->headers->get(self::REQUEST_FROM_HEADER, $this->requestIdService::UNKNOWN_APPLICATION);
        $this->requestIdService->setRequestFrom($requestFrom ?? $this->requestIdService::UNKNOWN_APPLICATION);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $response->headers->set(self::REQUEST_ID_HEADER, $this->requestIdService->getRequestId());
        $response->headers->set(self::REQUEST_FROM_HEADER, $this->requestIdService->getCurrentRequestFrom());
    }
}
