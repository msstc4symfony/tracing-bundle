<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\GuzzleHttp;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Msstc4Symfony\TracingBundle\GuzzleHttp\ChainedClientConfigurator;
use Msstc4Symfony\TracingBundle\GuzzleHttp\RequestIdGuzzleHandler;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChainedClientConfigurator::class)]
#[UsesClass(RequestIdGuzzleHandler::class)]
#[UsesClass(RequestIdService::class)]
final class ChainedClientConfiguratorTest extends TestCase
{
    public function testRunsTheOwnConfiguratorAndAddsTheTracing(): void
    {
        if (!class_exists(Client::class)) {
            self::markTestSkipped('guzzlehttp/guzzle is not installed');
        }

        $transport = new MockHandler([new Response()]);
        $client = new Client(['handler' => HandlerStack::create($transport)]);
        $configured = [];

        $chain = new ChainedClientConfigurator(
            static function (ClientInterface $client) use (&$configured): void {
                $configured[] = $client;
            },
            new RequestIdGuzzleHandler(new RequestIdService('shop', 'api')->setRequestId('abc')),
        );
        $chain($client);
        $client->request('GET', 'https://example.com');

        self::assertSame([$client], $configured);
        self::assertSame(['abc'], $transport->getLastRequest()?->getHeader('request-id'));
    }
}
