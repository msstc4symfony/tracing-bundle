<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Infrastructure\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Msstc4Symfony\TracingBundle\Application\CallerName;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\OutgoingTrace;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use Msstc4Symfony\TracingBundle\Infrastructure\Guzzle\ChainedClientConfigurator;
use Msstc4Symfony\TracingBundle\Infrastructure\Guzzle\TraceGuzzleMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChainedClientConfigurator::class)]
#[UsesClass(TraceGuzzleMiddleware::class)]
#[UsesClass(InMemoryTraceContextHolder::class)]
#[UsesClass(CallerName::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(OutgoingTrace::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(Baggage::class)]
final class ChainedClientConfiguratorTest extends TestCase
{
    public function testRunsTheOwnConfiguratorAndAddsTheTracing(): void
    {
        if (!class_exists(Client::class)) {
            self::markTestSkipped('guzzlehttp/guzzle is not installed');
        }

        $transport = new MockHandler([new Response(), new Response()]);
        $client = new Client(['handler' => HandlerStack::create($transport)]);
        $configured = [];
        $tracedWhileConfiguring = null;

        $chain = new ChainedClientConfigurator(
            static function (ClientInterface $client) use (&$configured, &$tracedWhileConfiguring, $transport): void {
                $configured[] = $client;
                $client->send(new Request('GET', 'https://example.com'));
                $tracedWhileConfiguring = $transport->getLastRequest()?->hasHeader('traceparent');
            },
            new TraceGuzzleMiddleware($holder = new InMemoryTraceContextHolder(), new CallerName('shop', 'api')),
        );
        $chain($client);
        $client->request('GET', 'https://example.com');

        self::assertSame([$client], $configured);
        self::assertFalse($tracedWhileConfiguring, 'the own configurator runs first');
        self::assertSame([$holder->current()->traceParent->toHeader()], $transport->getLastRequest()?->getHeader('traceparent'));
    }
}
