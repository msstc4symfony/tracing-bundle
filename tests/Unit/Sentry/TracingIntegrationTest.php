<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Sentry;

use Msstc4Symfony\TracingBundle\Sentry\Integration\TracingIntegration;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Scope;

#[CoversClass(TracingIntegration::class)]
#[UsesClass(RequestIdService::class)]
final class TracingIntegrationTest extends TestCase
{
    public function testAddsTheTraceToSentryEvents(): void
    {
        $storage = new RequestIdService('shop', 'api')->setRequestId('abc')->setRequestFrom('billing:api');
        $client = ClientBuilder::create([
            'integrations' => [new TracingIntegration($storage)],
            'default_integrations' => false,
        ])->getClient();
        SentrySdk::init()->bindClient($client);

        $event = new Scope()->applyToEvent(Event::createEvent());

        self::assertSame('abc', $event?->getExtra()['request_id'] ?? null);
        self::assertSame('billing:api', $event?->getExtra()['request_from'] ?? null);
        self::assertSame($storage->getRuntimeId(), $event?->getExtra()['runtime_id'] ?? null);
    }
}
