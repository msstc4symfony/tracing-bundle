<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\EventListener;

use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
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

    public function onRequest(RequestEvent $event): void
    {
        // Sub-requests (fragments, forwards) belong to the main request's trace.
        if (!$event->isMainRequest()) {
            return;
        }

        $this->requestIdService->reset();

        $headers = $event->getRequest()->headers;
        $requestId = $headers->get(self::REQUEST_ID_HEADER);
        if ($requestId === null || $requestId === '') {
            return;
        }

        $this->requestIdService
            ->setRequestId($requestId)
            ->setRequestFrom($headers->get(self::REQUEST_FROM_HEADER) ?? RequestIdServiceInterface::UNKNOWN_APPLICATION)
        ;
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set(self::REQUEST_ID_HEADER, $this->requestIdService->getRequestId());
        $headers->set(self::REQUEST_FROM_HEADER, $this->requestIdService->getCurrentRequestFrom());
    }
}
