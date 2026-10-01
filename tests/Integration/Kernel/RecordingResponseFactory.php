<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration\Kernel;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * mock_response_factory that remembers the headers of the last outgoing request.
 */
final class RecordingResponseFactory
{
    /** @var list<string> */
    public array $lastHeaders = [];

    /**
     * @param array<mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $headers = $options['headers'] ?? [];
        $this->lastHeaders = array_values(array_filter(is_array($headers) ? $headers : [], is_string(...)));

        return new MockResponse('{}');
    }
}
