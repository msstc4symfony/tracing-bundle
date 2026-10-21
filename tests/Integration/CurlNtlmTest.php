<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration;

use Msstc4Symfony\TracingBundle\Application\BaggageRecipients;
use Msstc4Symfony\TracingBundle\Application\CallerName;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\OutgoingTrace;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\AllowListTraceHttpClientDecorator;
use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\ClientOptions;
use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\PreparedRequest;
use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\RequestUrl;
use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\TraceHeaders;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\CurlHttpClient;

#[CoversClass(AllowListTraceHttpClientDecorator::class)]
#[UsesClass(ClientOptions::class)]
#[UsesClass(PreparedRequest::class)]
#[UsesClass(RequestUrl::class)]
#[UsesClass(TraceHeaders::class)]
#[UsesClass(InMemoryTraceContextHolder::class)]
#[UsesClass(CallerName::class)]
#[UsesClass(BaggageRecipients::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(OutgoingTrace::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(Baggage::class)]
#[RequiresPhpExtension('curl')]
final class CurlNtlmTest extends TestCase
{
    /** @var resource|null */
    private $server;

    /** @var resource|null */
    private $output;

    private int $port = 0;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(CurlHttpClient::class)) {
            self::markTestSkipped('symfony/http-client is not installed');
        }

        $features = curl_version()['features'] ?? 0;
        if (!is_int($features) || ($features & CURL_VERSION_NTLM) === 0) {
            self::markTestSkipped('libcurl is built without NTLM');
        }

        $server = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/ntlm-server.php'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($server);
        $this->server = $server;
        $this->output = $pipes[1];
        stream_set_timeout($this->output, 5);
        $this->port = (int) fgets($this->output);
        self::assertGreaterThan(0, $this->port, 'The NTLM server did not start.');
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_resource($this->output)) {
            fclose($this->output);
        }

        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
    }

    public function testFinishesTheNtlmHandshakeOfARequestToAListedHost(): void
    {
        $holder = new InMemoryTraceContextHolder();
        $holder->replace($holder->current()->withBaggage(Baggage::fromHeaders(['user=alice'])));

        $client = new AllowListTraceHttpClientDecorator(new CurlHttpClient(['proxy' => null, 'no_proxy' => '*']), $holder, new CallerName('orders', 'api'), new BaggageRecipients(['127.0.0.1']), 20, false);

        $response = $client->request('GET', sprintf('http://127.0.0.1:%d/', $this->port), ['auth_ntlm' => 'user:secret']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $response->getContent());
        self::assertSame([''], $response->getHeaders()['x-baggage-seen'] ?? null, 'No leg of the handshake carried baggage.');
    }
}
