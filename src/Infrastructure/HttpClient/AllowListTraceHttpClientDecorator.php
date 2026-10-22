<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Infrastructure\HttpClient;

use Msstc4Symfony\TracingBundle\Application\BaggageRecipients;
use Msstc4Symfony\TracingBundle\Application\CallerName;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Adds the trace headers to every outgoing request unless the caller set them, and baggage only for the hosts
 * BaggageRecipients allows. A request that carries this bundle's baggage follows its redirects in RedirectFollower
 * rather than in the transport, which would send the same baggage to whichever host the redirect names. An NTLM
 * request gets no baggage of this bundle: curl cannot finish the handshake with CURLOPT_MAXREDIRS 0, so its
 * redirects stay with the transport.
 *
 * Every response is an AsyncResponse of the one inner client — withOptions() keeps its options here rather than in a
 * copy of the inner client — so stream() interleaves them all, as the transport would.
 *
 * @internal
 */
#[Exclude]
final class AllowListTraceHttpClientDecorator implements HttpClientInterface, ResetInterface
{
    use AsyncDecoratorTrait;

    private readonly TraceHeaders $headers;

    /** @var array<mixed> the options given to withOptions(), merged into every request */
    private array $defaults = [];

    /**
     * @param int $maxRedirects the transport's default
     * @param bool $ntlmDefault whether the transport has NTLM credentials of its own
     */
    public function __construct(
        HttpClientInterface $inner,
        private readonly TraceContextHolderInterface $holder,
        private readonly CallerName $caller,
        private readonly BaggageRecipients $recipients,
        private readonly int $maxRedirects,
        private readonly bool $ntlmDefault,
    ) {
        $this->client = $inner;
        $this->headers = new TraceHeaders();
    }

    /**
     * @param array<mixed> $options
     */
    #[Override]
    public function request(string $method, string $url, array $options = []): AsyncResponse
    {
        $prepared = ClientOptions::prepare($url, $options, $this->defaults);
        $url = $prepared->url;
        $options = $prepared->options;
        $headers = $this->headers->of($options);
        if ($headers === null) {
            return new AsyncResponse($this->client, $method, $url, $options);
        }

        $baseUri = $options['base_uri'] ?? null;
        $target = RequestUrl::resolve($url, is_string($baseUri) || is_array($baseUri) ? $baseUri : null);
        $trace = $this->holder->current()->outgoing($this->caller->value);
        $withBaggage = !$this->usesNtlm($options) && $target instanceof RequestUrl && $this->recipients->allows($target->host);
        $maxRedirects = is_numeric($options['max_redirects'] ?? null) ? (int) $options['max_redirects'] : $this->maxRedirects;

        $sent = $options;
        $sent['headers'] = $this->headers->with($headers, $trace, $withBaggage);

        if (!$target instanceof RequestUrl || !$withBaggage || $maxRedirects <= 0) {
            return new AsyncResponse($this->client, $method, $url, $sent);
        }

        $sent['max_redirects'] = 0;
        $follower = new RedirectFollower(
            $this->headers,
            $this->recipients,
            $trace,
            $target,
            $method,
            $headers,
            $options,
            $maxRedirects,
        );

        return new AsyncResponse($this->client, $method, $url, $sent, $follower);
    }

    /**
     * @param array<mixed> $options
     */
    #[Override]
    public function withOptions(array $options): static
    {
        // Validated by the inner client as its own copy would be; the options themselves are applied here.
        $this->client->withOptions($options);

        $clone = clone $this;
        $clone->defaults = ClientOptions::defaults($options, $this->defaults);

        return $clone;
    }

    /**
     * @param array<mixed> $options merged with withOptions(): a null of the request turns the transport's NTLM off
     */
    private function usesNtlm(array $options): bool
    {
        return array_key_exists('auth_ntlm', $options) ? $options['auth_ntlm'] !== null : $this->ntlmDefault;
    }
}
