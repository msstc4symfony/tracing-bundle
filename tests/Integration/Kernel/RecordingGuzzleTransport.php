<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration\Kernel;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

final class RecordingGuzzleTransport
{
    public ?RequestInterface $lastRequest = null;

    public function __invoke(RequestInterface $request): PromiseInterface
    {
        $this->lastRequest = $request;

        return Create::promiseFor(new Response());
    }
}
