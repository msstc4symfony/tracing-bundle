<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration\Kernel;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Makes one downstream call while handling a request, as an application controller would.
 */
final readonly class OutgoingCallController
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function __invoke(): Response
    {
        $this->httpClient->request('GET', 'https://example.com/downstream')->getStatusCode();

        return new Response('called');
    }
}
