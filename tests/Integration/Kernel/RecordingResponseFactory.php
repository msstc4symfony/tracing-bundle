<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration\Kernel;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * mock_response_factory that remembers the headers of the outgoing requests and answers the URLs a test names
 * with a redirect.
 */
final class RecordingResponseFactory
{
    /** @var list<string> */
    public array $lastHeaders = [];

    /** @var array<string, list<string>> header lines by request URL */
    public array $headersByUrl = [];

    /** @var array<string, string> Location by request URL */
    public array $redirects = [];

    /**
     * @param array<mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $headers = $options['headers'] ?? [];
        $this->lastHeaders = array_values(array_filter(is_array($headers) ? $headers : [], is_string(...)));
        $this->headersByUrl[$url] = $this->lastHeaders;

        $location = $this->redirects[$url] ?? null;

        return $location === null ? new MockResponse('{}') : new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: ' . $location]]);
    }
}
