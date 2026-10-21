<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Infrastructure\HttpClient;

use Generator;
use LogicException;
use Msstc4Symfony\TracingBundle\Application\BaggageRecipients;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\OutgoingTrace;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * The AsyncResponse passthru that follows the redirects of one request in place of the transport, so each hop gets
 * the baggage BaggageRecipients allows for its host.
 *
 * It follows them as the native transport does: any 3xx but 304 with a Location; POST (anything on 303) turns into
 * GET without a body on 301, 302 and 303; Authorization, Cookie, auth_basic and auth_bearer — the inner client's
 * defaults included — go to the first origin only. Beyond the transport: the query option is not added to the
 * Location again, a seekable body is rewound before it is sent again, and a body that cannot be sent again (a
 * closure, an iterable, a pipe) ends the chain with the redirect response.
 *
 * @internal
 */
final class RedirectFollower
{
    private const NOT_MODIFIED = 304;

    private const METHOD_CHANGING_STATUSES = [301, 302, 303];

    private const SEE_OTHER = 303;

    private const CONTENT_HEADERS = ['Content-Length', 'Content-Type', 'Transfer-Encoding'];

    private const CREDENTIAL_HEADERS = ['Authorization', 'Cookie'];

    private const CREDENTIAL_OPTIONS = ['auth_basic', 'auth_bearer'];

    private RequestUrl $current;

    private int $redirects = 0;

    private ?float $startTime = null;

    /** The caller's body; null once a redirect turned the request into a GET. */
    private mixed $body;

    /** @var array<int, int> where each stream of the body stood when the request was made, by resource id */
    private array $positions = [];

    /**
     * @param array<mixed> $headers the caller's
     * @param array<mixed> $options the caller's
     *
     * @throws LogicException for an auth_ntlm option: curl cannot finish an NTLM handshake with CURLOPT_MAXREDIRS 0
     */
    public function __construct(
        private readonly TraceHeaders $traceHeaders,
        private readonly BaggageRecipients $recipients,
        private readonly OutgoingTrace $trace,
        private readonly RequestUrl $first,
        private string $method,
        private array $headers,
        private array $options,
        private readonly int $maxRedirects,
    ) {
        if (($options['auth_ntlm'] ?? null) !== null) {
            throw new LogicException('An NTLM request must leave its redirects to the transport.');
        }

        $this->current = $first;
        // The Location is complete; the transports do not merge the query option into it either.
        unset($this->options['query']);

        $this->body = $options['body'] ?? null;
        foreach ($this->streams($this->body) as $stream) {
            $position = ftell($stream);
            $this->positions[get_resource_id($stream)] = $position === false ? 0 : $position;
        }
    }

    /**
     * @return Generator<int, ChunkInterface, mixed, void>
     */
    public function __invoke(ChunkInterface $chunk, AsyncContext $context): Generator
    {
        try {
            $isFirst = $chunk->isFirst();
        } catch (TransportExceptionInterface) {
            $isFirst = false;
        }

        if (!$isFirst) {
            yield $chunk;

            return;
        }

        $startTime = $context->getInfo('start_time');
        $this->startTime ??= is_float($startTime) ? $startTime : microtime(true);

        $next = $this->next($context);
        if (!$next instanceof RequestUrl) {
            $context->passthru();

            yield $chunk;

            return;
        }

        $context->getResponse()->cancel();
        $context->replaceRequest($this->method, $next->url, $this->hop($next));
        $context->setInfo('redirect_count', ++$this->redirects);
        $context->setInfo('redirect_time', microtime(true) - $this->startTime);

        $this->current = $next;
    }

    /**
     * The URL to follow, with the method and body of the next hop set; null when the chain ends here.
     */
    private function next(AsyncContext $context): ?RequestUrl
    {
        $status = $context->getStatusCode();
        if ($status < 300 || $status >= 400 || $status === self::NOT_MODIFIED || $this->redirects >= $this->maxRedirects) {
            return null;
        }

        $locations = $context->getHeaders()['location'] ?? [];
        $location = is_array($locations) ? $locations[0] ?? null : null;
        // Against the URL sent, the query option included, as the transports resolve it.
        $sentUrl = $context->getInfo('url');
        $next = is_string($location) ? RequestUrl::resolve($location, is_string($sentUrl) ? $sentUrl : $this->current->url) : null;
        if (!$next instanceof RequestUrl) {
            return null;
        }

        if (in_array($status, self::METHOD_CHANGING_STATUSES, true) && ($this->method === 'POST' || $status === self::SEE_OTHER)) {
            $this->method = $this->method === 'HEAD' ? 'HEAD' : 'GET';
            unset($this->options['body'], $this->options['json']);
            $this->body = null;
            $this->headers = $this->traceHeaders->blocking($this->headers, ...self::CONTENT_HEADERS);

            return $next;
        }

        return $this->rewindBody() ? $next : null;
    }

    /**
     * @return array<mixed>
     */
    private function hop(RequestUrl $next): array
    {
        $options = $this->options;
        $headers = $this->traceHeaders->without($this->headers, 'Host');

        if ($next->origin !== $this->first->origin) {
            // Blocked, not removed: the inner client would otherwise apply the credentials of its own defaults.
            $headers = $this->traceHeaders->blocking($headers, ...self::CREDENTIAL_HEADERS);
            foreach (self::CREDENTIAL_OPTIONS as $credential) {
                $options[$credential] = null;
            }
        }

        $options['headers'] = $this->traceHeaders->with($headers, $this->trace, $this->recipients->allows($next->host));
        $options['max_redirects'] = 0;

        return $options;
    }

    /**
     * Whether the body can be sent again: none, a string, or form fields as they are; seekable streams, alone or in a
     * form, once back where they stood when the request was made. A lone stream is sent again as a closure: curl
     * reads a resource from where curl left it, whatever PHP did to it.
     */
    private function rewindBody(): bool
    {
        $body = $this->body;
        if ($body === null || is_string($body)) {
            return true;
        }

        if (is_array($body)) {
            foreach ($this->leaves($body) as $part) {
                if ($part instanceof StreamableInterface || (is_resource($part) && !$this->seek($part))) {
                    return false;
                }
            }

            return true;
        }

        if (!is_resource($body) || !$this->seek($body)) {
            return false;
        }

        $this->options['body'] = static fn (int $size): string => (string) fread($body, max(1, $size));

        return true;
    }

    private function seek(mixed $stream): bool
    {
        return is_resource($stream)
            && stream_get_meta_data($stream)['seekable']
            && fseek($stream, $this->positions[get_resource_id($stream)] ?? 0) === 0;
    }

    /**
     * @return list<resource>
     */
    private function streams(mixed $body): array
    {
        return array_values(array_filter($this->leaves($body), is_resource(...)));
    }

    /**
     * The values of a body as HttpClient sends them: the leaves of a form, the public properties of its objects
     * included (an object without any is sent as a string or not at all, so it is a leaf too).
     *
     * @return list<mixed>
     */
    private function leaves(mixed $value): array
    {
        if (is_object($value) && !$value instanceof StreamableInterface) {
            $vars = get_object_vars($value);
            if ($vars !== []) {
                $value = $vars;
            }
        }

        if (!is_array($value)) {
            return [$value];
        }

        $leaves = [];
        foreach ($value as $item) {
            array_push($leaves, ...$this->leaves($item));
        }

        return $leaves;
    }
}
