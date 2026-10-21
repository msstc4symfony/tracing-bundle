<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Infrastructure\HttpClient;

use ArrayIterator;
use Closure;
use LogicException;
use Msstc4Symfony\TracingBundle\Application\BaggageRecipients;
use Msstc4Symfony\TracingBundle\Application\CallerName;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\OutgoingTrace;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\AllowListTraceHttpClientDecorator;
use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\ClientOptions;
use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\PreparedRequest;
use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\RedirectFollower;
use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\RequestUrl;
use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\TraceHeaders;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use TypeError;

/**
 * @phpstan-type SentRequest array{method: string, url: string, headers: array<string, list<string>>, body: string, bodyType: string, maxRedirects: mixed, ntlm: bool, ntlmValue: mixed}
 */
#[CoversClass(AllowListTraceHttpClientDecorator::class)]
#[CoversClass(RedirectFollower::class)]
#[CoversClass(ClientOptions::class)]
#[CoversClass(PreparedRequest::class)]
#[UsesClass(RequestUrl::class)]
#[UsesClass(TraceHeaders::class)]
#[UsesClass(InMemoryTraceContextHolder::class)]
#[UsesClass(CallerName::class)]
#[UsesClass(BaggageRecipients::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(OutgoingTrace::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
#[UsesClass(Baggage::class)]
final class AllowListTraceHttpClientDecoratorTest extends TestCase
{
    /** @var list<SentRequest> */
    private array $sent = [];

    /** @var list<MockResponse> responses served in order, then 200 */
    private array $responses = [];

    private MockHttpClient $inner;

    private InMemoryTraceContextHolder $holder;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(MockHttpClient::class)) {
            self::markTestSkipped('symfony/http-client is not installed');
        }

        $this->holder = new InMemoryTraceContextHolder();
        $this->holder->replace($this->holder->current()->withBaggage(Baggage::fromHeaders(['user=alice'])));

        $this->inner = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = $options['body'] ?? '';
            $bodyType = get_debug_type($body);
            if ($body instanceof Closure) {
                $read = '';
                while (is_string($chunk = $body(16372)) && $chunk !== '') {
                    $read .= $chunk;
                }
                $body = $read;
            } elseif (is_resource($body)) {
                // From where it stands, as a transport reads it.
                $body = (string) stream_get_contents($body);
            }

            $this->sent[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $this->headersOf($options),
                'body' => is_string($body) ? $body : '',
                'bodyType' => $bodyType,
                'maxRedirects' => $options['max_redirects'] ?? null,
                'ntlm' => array_key_exists('auth_ntlm', $options),
                'ntlmValue' => $options['auth_ntlm'] ?? null,
            ];

            return array_shift($this->responses) ?? new MockResponse('done');
        });
    }

    public function testSendsTheBaggageToAnAllowedHostOnAnyPort(): void
    {
        $this->decorator(['*.example.com'])->request('GET', 'https://API.example.com:8443/orders?x=1')->getStatusCode();

        self::assertSame(['msstc4symfony.caller=orders%3Aapi,user=alice'], $this->sent[0]['headers']['baggage'] ?? null);
    }

    public function testSendsNoBaggageToAHostNotAllowedButTheTrace(): void
    {
        $this->holder->replace($this->holder->current()->continuing(
            TraceParent::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00') ?? self::fail('Invalid fixture.'),
            TraceState::fromHeaders(['rojo=1']),
            Baggage::fromHeaders(['user=alice']),
        ));

        $this->decorator(['orders'])->request('GET', 'https://example.com')->getStatusCode();

        self::assertArrayNotHasKey('baggage', $this->sent[0]['headers']);
        self::assertSame([$this->holder->current()->traceParent->toHeader()], $this->sent[0]['headers']['traceparent'] ?? null);
        self::assertSame(['rojo=1'], $this->sent[0]['headers']['tracestate'] ?? null);
    }

    public function testSendsTheCallersBaggageUnchangedToAHostNotAllowed(): void
    {
        $this->decorator([])->request('GET', 'https://example.com', ['headers' => ['baggage' => ['sentry-trace_id=abc', 'tenant=7']]])->getStatusCode();

        self::assertSame(['sentry-trace_id=abc', 'tenant=7'], $this->sent[0]['headers']['baggage'] ?? null);
    }

    public function testAddsTheMissingMembersToTheCallersBaggageForAnAllowedHost(): void
    {
        $this->decorator(['example.com'])->request('GET', 'https://example.com', ['headers' => ['baggage' => 'tenant=7']])->getStatusCode();

        self::assertSame(['tenant=7, msstc4symfony.caller=orders%3Aapi,user=alice'], $this->sent[0]['headers']['baggage'] ?? null);
    }

    public function testTakesTheHostOfTheBaseUriForARelativeUrl(): void
    {
        $decorator = $this->decorator(['orders']);

        $decorator->request('GET', '/x', ['base_uri' => 'http://orders:8080/api/'])->getStatusCode();
        $decorator->request('GET', '/x', ['base_uri' => 'http://payments/'])->getStatusCode();

        self::assertArrayHasKey('baggage', $this->sent[0]['headers']);
        self::assertSame('http://orders:8080/x', $this->sent[0]['url']);
        self::assertArrayNotHasKey('baggage', $this->sent[1]['headers']);
    }

    public function testTakesTheHostOfABaseUriSetWithOptions(): void
    {
        $decorator = $this->decorator(['orders']);

        $decorator->withOptions(['base_uri' => 'http://orders/'])->request('GET', '/x')->getStatusCode();
        $decorator->withOptions(['base_uri' => 'http://orders/'])->withOptions(['timeout' => 1])->request('GET', '/x')->getStatusCode();
        $decorator->withOptions(['base_uri' => 'http://payments/'])->request('GET', '/x')->getStatusCode();
        $decorator->withOptions(['base_uri' => 'http://payments/'])->request('GET', '/x', ['base_uri' => 'http://orders/'])->getStatusCode();

        self::assertArrayHasKey('baggage', $this->sent[0]['headers']);
        self::assertArrayHasKey('baggage', $this->sent[1]['headers']);
        self::assertArrayNotHasKey('baggage', $this->sent[2]['headers']);
        self::assertArrayHasKey('baggage', $this->sent[3]['headers']);
    }

    public function testWithOptionsReturnsACopy(): void
    {
        $decorator = $this->decorator(['orders']);
        $decorator->withOptions(['base_uri' => 'http://orders/', 'max_redirects' => 0]);

        $this->responses = [$this->redirect(302, 'http://orders/next')];
        $decorator->request('GET', 'http://orders/x')->getStatusCode();

        self::assertCount(2, $this->sent);
    }

    public function testTakesTheHostOfAnAbsoluteUrlOverTheBaseUri(): void
    {
        $this->decorator(['orders'])->request('GET', 'http://payments/x', ['base_uri' => 'http://orders/'])->getStatusCode();

        self::assertArrayNotHasKey('baggage', $this->sent[0]['headers']);
    }

    public function testMatchesAnInternationalHostByItsPunycode(): void
    {
        if (!defined('INTL_IDNA_VARIANT_UTS46')) {
            self::markTestSkipped('ext-intl or symfony/polyfill-intl-idn is not installed');
        }

        $this->decorator(['xn--bcher-kva.example'])->request('GET', 'http://bücher.example/')->getStatusCode();

        self::assertArrayHasKey('baggage', $this->sent[0]['headers']);
    }

    public function testDropsTheBaggageWhenARedirectLeavesTheAllowedHosts(): void
    {
        $this->responses = [$this->redirect(302, 'https://evil.example/collect')];

        $response = $this->decorator(['orders'])->request('GET', 'http://orders/x');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('done', $response->getContent());
        self::assertCount(2, $this->sent);
        self::assertSame('https://evil.example/collect', $this->sent[1]['url']);
        self::assertArrayHasKey('baggage', $this->sent[0]['headers']);
        self::assertArrayNotHasKey('baggage', $this->sent[1]['headers']);
        self::assertSame($this->sent[0]['headers']['traceparent'], $this->sent[1]['headers']['traceparent'] ?? null);
        self::assertSame(1, $response->getInfo('redirect_count'));
    }

    public function testSendsTheBaggageAcrossRedirectsBetweenAllowedHosts(): void
    {
        $this->responses = [$this->redirect(301, '/moved'), $this->redirect(307, 'http://payments/y')];

        $this->decorator(['orders', 'payments'])->request('GET', 'http://orders/x')->getStatusCode();

        self::assertSame(['http://orders/x', 'http://orders/moved', 'http://payments/y'], array_column($this->sent, 'url'));
        foreach ($this->sent as $request) {
            self::assertSame(['msstc4symfony.caller=orders%3Aapi,user=alice'], $request['headers']['baggage'] ?? null);
            self::assertSame(0, $request['maxRedirects'], 'The transport must not follow redirects itself.');
        }
    }

    public function testKeepsTheCallersBaggageAcrossARedirectToAHostNotAllowed(): void
    {
        $this->responses = [$this->redirect(302, 'http://payments/y')];

        $this->decorator(['orders'])->request('GET', 'http://orders/x', ['headers' => ['baggage' => 'tenant=7']])->getStatusCode();

        self::assertSame(['tenant=7, msstc4symfony.caller=orders%3Aapi,user=alice'], $this->sent[0]['headers']['baggage'] ?? null);
        self::assertSame(['tenant=7'], $this->sent[1]['headers']['baggage'] ?? null);
    }

    #[TestWith([301, 'POST', 'GET'])]
    #[TestWith([302, 'POST', 'GET'])]
    #[TestWith([303, 'PUT', 'GET'])]
    #[TestWith([303, 'HEAD', 'HEAD'])]
    public function testTurnsTheRequestIntoAGetLikeTheTransport(int $status, string $method, string $redirectedMethod): void
    {
        $this->responses = [$this->redirect($status, 'http://orders/y')];

        $this->decorator(['orders'])->request($method, 'http://orders/x', ['body' => 'payload', 'headers' => ['Content-Type' => 'text/plain']])->getStatusCode();

        self::assertSame($redirectedMethod, $this->sent[1]['method']);
        self::assertSame('', $this->sent[1]['body']);
        self::assertArrayNotHasKey('content-type', $this->sent[1]['headers']);
    }

    #[TestWith([307])]
    #[TestWith([308])]
    #[TestWith([301, 'PUT'])]
    public function testResendsTheRequestUnchanged(int $status, string $method = 'POST'): void
    {
        $this->responses = [$this->redirect($status, 'http://orders/y')];

        $this->decorator(['orders'])->request($method, 'http://orders/x', ['body' => 'payload', 'headers' => ['Content-Type' => 'text/plain']])->getStatusCode();

        self::assertSame($method, $this->sent[1]['method']);
        self::assertSame('payload', $this->sent[1]['body']);
        self::assertSame(['text/plain'], $this->sent[1]['headers']['content-type'] ?? null);
    }

    public function testKeepsCredentialsOnlyForTheFirstOrigin(): void
    {
        $this->responses = [$this->redirect(302, '/same'), $this->redirect(302, 'https://payments/y'), $this->redirect(302, 'http://orders/back')];

        $this->decorator(['orders', 'payments'])->request('GET', 'http://orders/x', [
            'auth_bearer' => 'secret',
            'headers' => ['Cookie' => 'session=1', 'Host' => 'orders'],
        ])->getStatusCode();

        self::assertSame(['Bearer secret'], $this->sent[1]['headers']['authorization'] ?? null);
        self::assertSame(['session=1'], $this->sent[1]['headers']['cookie'] ?? null);
        self::assertArrayNotHasKey('authorization', $this->sent[2]['headers']);
        self::assertArrayNotHasKey('cookie', $this->sent[2]['headers']);
        self::assertArrayNotHasKey('host', $this->sent[2]['headers']);
        self::assertSame(['Bearer secret'], $this->sent[3]['headers']['authorization'] ?? null, 'Back on the first origin, as the transports do.');
        self::assertSame(['session=1'], $this->sent[3]['headers']['cookie'] ?? null);
    }

    public function testKeepsTheInnerClientsDefaultCredentialsFromAnotherOrigin(): void
    {
        $this->inner = $this->inner->withOptions([
            'auth_bearer' => 'secret',
            'headers' => ['Cookie' => 'session=1', 'Content-Type' => 'text/plain'],
        ]);
        $this->responses = [$this->redirect(303, 'https://payments/y')];

        $this->decorator(['orders'])->request('POST', 'http://orders/x', ['body' => 'payload'])->getStatusCode();

        self::assertSame(['Bearer secret'], $this->sent[0]['headers']['authorization'] ?? null);
        self::assertArrayNotHasKey('authorization', $this->sent[1]['headers']);
        self::assertArrayNotHasKey('cookie', $this->sent[1]['headers']);
        self::assertArrayNotHasKey('content-type', $this->sent[1]['headers']);
        self::assertSame(['payments'], $this->sent[1]['headers']['host'] ?? ['payments'], "No Host header but the transport's own.");
    }

    public function testGivesNoNtlmOptionToAHopOfARequestWithoutIt(): void
    {
        $this->responses = [$this->redirect(302, 'https://payments/y')];

        $this->decorator(['orders'])->request('GET', 'http://orders/x')->getStatusCode();

        self::assertFalse($this->sent[1]['ntlm'], 'The native transport rejects the option.');
    }

    #[TestWith([300])]
    #[TestWith([305])]
    public function testFollowsAnyRedirectStatusButNotModified(int $status): void
    {
        $this->responses = [$this->redirect($status, 'http://orders/y')];

        self::assertSame(200, $this->decorator(['orders'])->request('GET', 'http://orders/x')->getStatusCode());
    }

    public function testFollowsTheFirstLocation(): void
    {
        $this->responses = [new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: http://orders/first', 'Location: http://orders/second']])];

        $this->decorator(['orders'])->request('GET', 'http://orders/x')->getStatusCode();

        self::assertSame('http://orders/first', $this->sent[1]['url']);
    }

    #[TestWith(['https://payments/y'])]
    #[TestWith(['/y'])]
    public function testSendsTheQueryOptionWithTheFirstRequestOnly(string $location): void
    {
        $this->responses = [$this->redirect(302, $location)];

        $this->decorator(['orders', 'payments'])->request('GET', 'http://orders/x', ['query' => ['api_key' => 'SECRET']])->getStatusCode();

        self::assertSame('http://orders/x?api_key=SECRET', $this->sent[0]['url']);
        self::assertStringNotContainsString('api_key', $this->sent[1]['url']);
    }

    /**
     * @param array<string, string> $requestOptions
     * @param array<string, string> $clientOptions
     */
    #[TestWith([['auth_ntlm' => 'user:pass'], [], false, 'user:pass'])]
    #[TestWith([[], ['auth_ntlm' => 'user:pass'], false, 'user:pass'])]
    #[TestWith([[], [], true, null])]
    public function testSendsNoBaggageWithNtlmAndLeavesItsRedirectsToTheTransport(
        array $requestOptions,
        array $clientOptions,
        bool $ntlmDefault,
        ?string $sentNtlm,
    ): void {
        $this->responses = [$this->redirect(302, 'https://payments/y')];

        $client = $this->decorator(['orders'], ntlmDefault: $ntlmDefault);
        $client = $clientOptions === [] ? $client : $client->withOptions($clientOptions);

        $status = $client->request('GET', 'http://orders/x', $requestOptions)->getStatusCode();

        self::assertSame(302, $status);
        self::assertCount(1, $this->sent);
        self::assertArrayNotHasKey('baggage', $this->sent[0]['headers']);
        self::assertSame([$this->holder->current()->traceParent->toHeader()], $this->sent[0]['headers']['traceparent'] ?? null);
        self::assertNull($this->sent[0]['maxRedirects']);
        self::assertSame($sentNtlm, $this->sent[0]['ntlmValue']);
    }

    /**
     * @param array<string, null> $requestOptions
     * @param array<string, string|null> $clientOptions
     */
    #[TestWith([['auth_ntlm' => null], [], true])]
    #[TestWith([[], ['auth_ntlm' => null], true])]
    #[TestWith([['auth_ntlm' => null], ['auth_ntlm' => 'user:pass'], false])]
    public function testSendsTheBaggageWhenNtlmIsTurnedOff(array $requestOptions, array $clientOptions, bool $ntlmDefault): void
    {
        $client = $this->decorator(['orders'], ntlmDefault: $ntlmDefault);
        $client = $clientOptions === [] ? $client : $client->withOptions($clientOptions);
        $client->request('GET', 'http://orders/x', $requestOptions)->getStatusCode();

        self::assertSame(['msstc4symfony.caller=orders%3Aapi,user=alice'], $this->sent[0]['headers']['baggage'] ?? null);
        self::assertSame(0, $this->sent[0]['maxRedirects']);
        self::assertTrue($this->sent[0]['ntlm']);
        self::assertNull($this->sent[0]['ntlmValue']);
    }

    public function testRefusesToFollowTheRedirectsOfAnNtlmRequest(): void
    {
        $this->expectException(LogicException::class);

        new RedirectFollower(
            new TraceHeaders(),
            new BaggageRecipients(['orders']),
            $this->holder->current()->outgoing('orders:api'),
            RequestUrl::resolve('http://orders/x') ?? self::fail('Invalid fixture.'),
            'GET',
            [],
            ['auth_ntlm' => 'user:pass'],
            20,
        );
    }

    public function testRewindsAStreamedBodyItSendsAgain(): void
    {
        $body = fopen('php://memory', 'r+');
        self::assertIsResource($body);
        fwrite($body, 'payload');
        rewind($body);
        $this->responses = [$this->redirect(307, 'http://orders/y')];

        $this->decorator(['orders'])->request('PUT', 'http://orders/x', ['body' => $body])->getStatusCode();

        self::assertSame('payload', $this->sent[1]['body']);
    }

    public function testReturnsTheRedirectWhenTheBodyCannotBeSentAgain(): void
    {
        $this->responses = [$this->redirect(307, 'http://orders/y')];

        $response = $this->decorator(['orders'])->request('PUT', 'http://orders/x', ['body' => static fn (): string => '']);

        self::assertSame(307, $response->getStatusCode());
        self::assertCount(1, $this->sent);
    }

    public function testSetsTheRedirectTime(): void
    {
        $this->responses = [$this->redirect(302, '/y')];

        $response = $this->decorator(['orders'])->request('GET', 'http://orders/x');
        $response->getStatusCode();

        self::assertIsFloat($response->getInfo('redirect_time'));
        self::assertGreaterThan(0.0, $response->getInfo('redirect_time'));
        self::assertLessThan(60.0, $response->getInfo('redirect_time'));
    }

    public function testAnswersEveryRequestWithAnAsyncResponse(): void
    {
        self::assertInstanceOf(AsyncResponse::class, $this->decorator(['orders'])->request('GET', 'http://payments/x'));
        self::assertInstanceOf(AsyncResponse::class, $this->decorator(['orders'], 0)->request('GET', 'http://orders/x'));
        self::assertInstanceOf(AsyncResponse::class, $this->decorator(['orders'])->request('GET', 'http://orders/x'));
    }

    public function testStreamsResponsesOfSeveralCopies(): void
    {
        $decorator = $this->decorator(['orders']);
        $responses = [
            $decorator->request('GET', 'http://payments/a'),
            $decorator->withOptions(['timeout' => 5])->request('GET', 'http://payments/b'),
            $decorator->request('GET', 'http://orders/c'),
        ];

        $last = 0;
        foreach ($decorator->stream($responses) as $chunk) {
            $last += $chunk->isLast() ? 1 : 0;
        }

        self::assertSame(3, $last);
    }

    public function testStreamsOneResponse(): void
    {
        $decorator = $this->decorator(['orders']);
        $response = $decorator->request('GET', 'http://orders/x');

        $chunks = 0;
        foreach ($decorator->stream($response) as $streamed => $chunk) {
            self::assertSame($response, $streamed);
            $chunks++;
        }

        self::assertGreaterThan(0, $chunks);
    }

    public function testStreamsFollowedAndOtherResponsesTogether(): void
    {
        // Served in request order: the redirect, the other response, then the redirect's next hop.
        $this->responses = [$this->redirect(302, '/y'), new MockResponse('other'), new MockResponse(['a', 'b', 'c'])];
        $decorator = $this->decorator(['orders']);
        $followed = $decorator->request('GET', 'http://orders/x');
        $other = $decorator->withOptions(['timeout' => 5])->request('GET', 'http://payments/x');

        $order = [];
        foreach ($decorator->stream([$followed, $other]) as $response => $chunk) {
            if ($chunk->isLast()) {
                $order[] = $response === $followed ? 'followed' : 'other';
            }
        }

        self::assertSame(['other', 'followed'], $order, 'The other response does not wait for the redirect chain.');
        self::assertSame('abc', $followed->getContent());
        self::assertSame('other', $other->getContent());
    }

    public function testAppliesTheOptionsOfWithOptionsItself(): void
    {
        $decorator = $this->decorator(['orders'])->withOptions([
            'base_uri' => 'http://orders/api/',
            'headers' => ['X-Tenant' => '7'],
            'query' => ['lang' => 'en'],
        ]);

        $decorator->request('GET', 'x', ['query' => ['page' => 2]])->getStatusCode();

        self::assertSame('http://orders/api/x?page=2&lang=en', $this->sent[0]['url']);
        self::assertSame(['7'], $this->sent[0]['headers']['x-tenant'] ?? null);
        self::assertArrayHasKey('baggage', $this->sent[0]['headers']);
    }

    public function testSendsTheDefaultQueryWithRelativeUrlsOnly(): void
    {
        $decorator = $this->decorator(['orders'])->withOptions(['base_uri' => 'http://orders/', 'query' => ['api_key' => 'SECRET']]);

        $decorator->request('GET', 'http://payments/next')->getStatusCode();
        $decorator->request('GET', 'http://orders/next')->getStatusCode();

        self::assertSame('http://payments/next', $this->sent[0]['url']);
        self::assertSame('http://orders/next', $this->sent[1]['url']);
    }

    public function testLetsTheUrlQueryWinOverTheDefaultQuery(): void
    {
        $this->decorator(['orders'])->withOptions(['base_uri' => 'http://orders/', 'query' => ['page' => '1', 'lang' => 'en']])
            ->request('GET', '/x?page=2')->getStatusCode()
        ;

        self::assertSame('http://orders/x?page=2&lang=en', $this->sent[0]['url']);
    }

    public function testForgetsTheDefaultQueryOfAnEarlierWithOptions(): void
    {
        $this->decorator(['orders'])->withOptions(['base_uri' => 'http://orders/', 'query' => ['a' => '1']])->withOptions(['headers' => ['X-A' => '1']])
            ->request('GET', '/x')->getStatusCode()
        ;

        self::assertSame('http://orders/x', $this->sent[0]['url']);
    }

    public function testTakesHeadersGivenAsTraversableAfterWithOptions(): void
    {
        $this->decorator(['orders'])->withOptions(['headers' => ['X-A' => '1']])
            ->request('GET', 'http://orders/x', ['headers' => new ArrayIterator(['X-B' => '2'])])->getStatusCode()
        ;

        self::assertSame(['1'], $this->sent[0]['headers']['x-a'] ?? null);
        self::assertSame(['2'], $this->sent[0]['headers']['x-b'] ?? null);
    }

    public function testSendsAStreamAgainOnEveryHopFromWhereItStarted(): void
    {
        $body = fopen('php://temp', 'r+');
        self::assertIsResource($body);
        fwrite($body, 'PAYLOAD');
        fseek($body, 3);
        $this->responses = [$this->redirect(307, 'http://orders/a'), $this->redirect(308, 'http://orders/b')];

        $response = $this->decorator(['orders'])->request('PUT', 'http://orders/x', ['body' => $body]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['LOAD', 'LOAD', 'LOAD'], array_column($this->sent, 'body'));
    }

    public function testRewindsTheStreamsOfAFormToWhereTheyStarted(): void
    {
        $file = fopen('php://temp', 'r+');
        self::assertIsResource($file);
        fwrite($file, 'skip-content');
        fseek($file, 5);
        $this->responses = [$this->redirect(307, 'http://orders/a'), $this->redirect(307, 'http://orders/b')];

        $other = fopen('php://temp', 'r+');
        self::assertIsResource($other);
        fwrite($other, 'omit-other');
        fseek($other, 5);

        $this->decorator(['orders'])->request('POST', 'http://orders/x', ['body' => ['file' => $file, 'more' => ['other' => $other]]])->getStatusCode();

        self::assertCount(3, $this->sent);
        self::assertStringNotContainsString('skip', $this->sent[2]['body']);
        self::assertStringNotContainsString('omit', $this->sent[2]['body']);
        self::assertStringContainsString('content', $this->sent[2]['body']);
        self::assertStringContainsString('other', $this->sent[2]['body']);
    }

    public function testLeavesARelativeUrlWithTheDefaultQueryToTheInnerClientsBaseUri(): void
    {
        $this->inner = $this->inner->withOptions(['base_uri' => 'http://orders/api/']);

        $this->decorator(['orders'])->withOptions(['query' => ['k' => 'v']])->request('GET', 'x?a=1')->getStatusCode();

        self::assertSame('http://orders/api/x?a=1&k=v', $this->sent[0]['url']);
        self::assertArrayNotHasKey('baggage', $this->sent[0]['headers'], 'Only the inner client knows the host.');
    }

    public function testSendsNoDefaultQueryToASchemeRelativeUrl(): void
    {
        $this->inner = $this->inner->withOptions(['base_uri' => 'http://orders/']);

        $this->decorator(['orders'])->withOptions(['query' => ['k' => 'v']])->request('GET', '//payments/x')->getStatusCode();

        self::assertSame('http://payments/x', $this->sent[0]['url']);
        self::assertArrayNotHasKey('baggage', $this->sent[0]['headers']);
    }

    public function testLetsTheInnerClientReportADefaultQueryThatIsNotAnArray(): void
    {
        $this->expectException(TypeError::class);

        $this->decorator(['orders'])->withOptions(['base_uri' => 'http://orders/', 'query' => 'k=v'])->request('GET', '/x')->getStatusCode();
    }

    public function testSendsNoDefaultQueryToAnAbsoluteUrlWithoutABaseUri(): void
    {
        $this->decorator(['orders'])->withOptions(['query' => ['api_key' => 'SECRET']])->request('GET', 'http://payments/next')->getStatusCode();

        self::assertSame('http://payments/next', $this->sent[0]['url']);
    }

    public function testLetsTheUrlQueryWinOverTheDefaultQueryOfARelativeUrl(): void
    {
        $this->inner = $this->inner->withOptions(['base_uri' => 'http://orders/']);

        $this->decorator(['orders'])->withOptions(['query' => ['page' => '1']])->request('GET', 'x?page=2')->getStatusCode();

        self::assertSame('http://orders/x?page=2', $this->sent[0]['url']);
    }

    public function testLetsTheInnerClientReportAQueryThatIsNotAnArray(): void
    {
        $this->expectException(TypeError::class);

        $this->decorator(['orders'])->withOptions(['base_uri' => 'http://orders/', 'query' => ['k' => 'v']])
            ->request('GET', '/x', ['query' => 'a=1'])->getStatusCode()
        ;
    }

    public function testTakesABaseUriGivenAsParts(): void
    {
        $this->decorator(['orders'])->withOptions(['query' => ['k' => 'v']])
            ->request('GET', '/x', ['base_uri' => ['scheme' => 'http:', 'authority' => '//orders', 'path' => '/', 'query' => null, 'fragment' => null]])->getStatusCode()
        ;

        self::assertSame('http://orders/x?k=v', $this->sent[0]['url']);
        self::assertArrayHasKey('baggage', $this->sent[0]['headers']);
    }

    public function testSendsAFormWithObjectsAgain(): void
    {
        $this->responses = [$this->redirect(307, 'http://orders/y')];
        $name = new class implements Stringable {
            #[Override]
            public function __toString(): string
            {
                return 'alice';
            }
        };

        $response = $this->decorator(['orders'])->request('POST', 'http://orders/x', ['body' => ['name' => $name]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('name=alice', $this->sent[1]['body']);
    }

    public function testRewindsTheStreamsOfObjectsInAForm(): void
    {
        $file = fopen('php://temp', 'r+');
        self::assertIsResource($file);
        fwrite($file, 'skip-content');
        fseek($file, 5);
        $this->responses = [$this->redirect(307, 'http://orders/y')];

        $response = $this->decorator(['orders'])->request('POST', 'http://orders/x', ['body' => ['a' => 'x', 'o' => (object) ['f' => $file]]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('content', $this->sent[1]['body']);
        self::assertStringNotContainsString('skip', $this->sent[1]['body']);
    }

    public function testReturnsTheRedirectWhenAnObjectInAFormHoldsAPipe(): void
    {
        $pipe = popen('true', 'r');
        self::assertIsResource($pipe);
        $this->responses = [$this->redirect(307, 'http://orders/y')];

        try {
            self::assertSame(307, $this->decorator(['orders'])->request('POST', 'http://orders/x', ['body' => ['o' => (object) ['f' => $pipe]]])->getStatusCode());
        } finally {
            pclose($pipe);
        }
    }

    public function testLetsTheTransportValidateTheOptionsOfWithOptions(): void
    {
        $decorator = new AllowListTraceHttpClientDecorator(new NativeHttpClient(), $this->holder, new CallerName('orders', 'api'), new BaggageRecipients([]), 20, false);

        $this->expectException(InvalidArgumentException::class);

        $decorator->withOptions(['no_such_option' => true]);
    }

    #[TestWith(['1'])]
    #[TestWith([1.0])]
    public function testReadsANumericMaxRedirects(string|float $maxRedirects): void
    {
        $this->responses = [$this->redirect(302, '/a'), $this->redirect(302, '/b')];

        self::assertSame(302, $this->decorator(['orders'])->request('GET', 'http://orders/x', ['max_redirects' => $maxRedirects])->getStatusCode());
        self::assertCount(2, $this->sent);
    }

    public function testSendsAStreamAgainAsAClosure(): void
    {
        $body = fopen('php://temp', 'r+');
        self::assertIsResource($body);
        fwrite($body, 'payload');
        rewind($body);
        $this->responses = [$this->redirect(307, 'http://orders/y')];

        $this->decorator(['orders'])->request('PUT', 'http://orders/x', ['body' => $body])->getStatusCode();

        self::assertSame(Closure::class, $this->sent[1]['bodyType'], 'Curl reads a resource from where curl left it, whatever PHP rewound.');
        self::assertSame('payload', $this->sent[1]['body']);
    }

    public function testRewindsTheStreamsOfAFormItSendsAgain(): void
    {
        $file = fopen('php://temp', 'r+');
        self::assertIsResource($file);
        fwrite($file, 'content');
        rewind($file);
        $this->responses = [$this->redirect(307, 'http://orders/y')];

        $this->decorator(['orders'])->request('POST', 'http://orders/x', ['body' => ['name' => 'a', 'kind' => 'b', 'file' => $file]])->getStatusCode();

        self::assertCount(2, $this->sent);
        self::assertStringContainsString('content', $this->sent[1]['body']);
    }

    public function testReturnsTheRedirectWhenAFormPartCannotBeSentAgain(): void
    {
        $pipe = popen('true', 'r');
        self::assertIsResource($pipe);
        $this->responses = [$this->redirect(307, 'http://orders/y')];

        try {
            $response = $this->decorator(['orders'])->request('POST', 'http://orders/x', ['body' => ['file' => $pipe]]);

            self::assertSame(307, $response->getStatusCode());
        } finally {
            pclose($pipe);
        }
    }

    public function testResolvesTheLocationAgainstTheUrlSent(): void
    {
        $this->responses = [$this->redirect(302, '#top')];

        $this->decorator(['orders'])->request('GET', 'http://orders/x', ['query' => ['page' => 2]])->getStatusCode();

        self::assertSame('http://orders/x?page=2#top', $this->sent[1]['url']);
    }

    public function testFollowsAtMostMaxRedirects(): void
    {
        $this->responses = [$this->redirect(302, '/a'), $this->redirect(302, '/b')];

        $response = $this->decorator(['orders'])->request('GET', 'http://orders/x', ['max_redirects' => 1]);

        self::assertSame(302, $response->getStatusCode());
        self::assertCount(2, $this->sent);
    }

    public function testFollowsNoRedirectWhenTheyAreOff(): void
    {
        $this->responses = [$this->redirect(302, '/a')];

        self::assertSame(302, $this->decorator(['orders'], 0)->request('GET', 'http://orders/x')->getStatusCode());
        self::assertCount(1, $this->sent);
        self::assertNull($this->sent[0]['maxRedirects'], 'The transport keeps its own setting.');
    }

    public function testTakesMaxRedirectsFromWithOptions(): void
    {
        $this->responses = [$this->redirect(302, '/a')];

        self::assertSame(302, $this->decorator(['orders'])->withOptions(['max_redirects' => 0])->request('GET', 'http://orders/x')->getStatusCode());
    }

    public function testLeavesRedirectsWithoutBaggageToTheTransport(): void
    {
        $this->decorator(['orders'])->request('GET', 'http://payments/x')->getStatusCode();

        self::assertNull($this->sent[0]['maxRedirects']);
    }

    #[TestWith([304, 'http://orders/y'])]
    #[TestWith([200, 'http://orders/y'])]
    #[TestWith([400, 'http://orders/y'])]
    #[TestWith([302, null])]
    #[TestWith([302, 'ftp://orders/y'])]
    public function testReturnsAResponseThatCannotBeFollowed(int $status, ?string $location): void
    {
        $this->responses = [$this->redirect($status, $location)];

        self::assertSame($status, $this->decorator(['orders'])->request('GET', 'http://orders/x')->getStatusCode());
        self::assertCount(1, $this->sent);
    }

    /**
     * @param list<string> $allowedHosts
     */
    private function decorator(array $allowedHosts, int $maxRedirects = 20, bool $ntlmDefault = false): AllowListTraceHttpClientDecorator
    {
        return new AllowListTraceHttpClientDecorator($this->inner, $this->holder, new CallerName('orders', 'api'), new BaggageRecipients($allowedHosts), $maxRedirects, $ntlmDefault);
    }

    private function redirect(int $status, ?string $location): MockResponse
    {
        return new MockResponse('', ['http_code' => $status, 'response_headers' => $location === null ? [] : ['Location: ' . $location]]);
    }

    /**
     * @param array<mixed> $options
     *
     * @return array<string, list<string>>
     */
    private function headersOf(array $options): array
    {
        $headers = [];
        $lines = $options['headers'] ?? [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (is_string($line) && str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))][] = trim($value);
            }
        }

        return $headers;
    }
}
