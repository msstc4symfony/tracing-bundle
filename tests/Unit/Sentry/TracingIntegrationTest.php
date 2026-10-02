<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Sentry;

use Msstc4Symfony\TracingBundle\Sentry\Integration\TracingIntegration;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Scope;

#[CoversClass(TracingIntegration::class)]
#[UsesClass(RequestIdService::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceContext::class)]
final class TracingIntegrationTest extends TestCase
{
    private const string TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(ClientBuilder::class)) {
            self::markTestSkipped('sentry/sentry is not installed');
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        if (class_exists(SentrySdk::class)) {
            SentrySdk::init();
        }
    }

    public function testAddsTheTraceToSentryEvents(): void
    {
        $storage = new RequestIdService('shop', 'api')->setRequestId('abc')->setRequestFrom('billing:api');

        $event = $this->captureWith(new TracingIntegration($storage), new Scope());

        self::assertSame('abc', $event->getExtra()['request_id'] ?? null);
        self::assertSame('billing:api', $event->getExtra()['request_from'] ?? null);
        self::assertSame($storage->getRuntimeId(), $event->getExtra()['runtime_id'] ?? null);
        self::assertArrayNotHasKey('trace_id', $event->getTags());
        self::assertArrayNotHasKey('span_id', $event->getTags());
    }

    public function testTagsEventsWithTheW3cTraceWhenEnabled(): void
    {
        $storage = new RequestIdService('shop', 'api');
        $storage->continueTrace($this->remoteTraceParent());

        $event = Event::createEvent();
        $event->setTags(['env' => 'prod']);

        $event = $this->captureWith(new TracingIntegration($storage, $storage), new Scope(), $event);

        self::assertSame(
            ['env' => 'prod', 'trace_id' => self::TRACE_ID, 'span_id' => $storage->getTraceParent()->parentId],
            $event->getTags(),
        );
    }

    public function testLeavesSentryTraceContextToSentry(): void
    {
        $storage = new RequestIdService('shop', 'api');
        $storage->continueTrace($this->remoteTraceParent());

        $scope = new Scope();

        $event = $this->captureWith(new TracingIntegration($storage, $storage), $scope);

        $sentryTrace = $event->getContexts()['trace'] ?? [];
        self::assertSame((string) $scope->getPropagationContext()->getTraceId(), $sentryTrace['trace_id'] ?? null);
        self::assertNotSame(self::TRACE_ID, $sentryTrace['trace_id'] ?? null);
    }

    public function testKeepsTagsTheApplicationSetOnTheEvent(): void
    {
        $storage = new RequestIdService('shop', 'api');
        $storage->continueTrace($this->remoteTraceParent());

        $event = Event::createEvent();
        $event->setTags(['trace_id' => 'app-trace', 'span_id' => 'app-span']);

        $event = $this->captureWith(new TracingIntegration($storage, $storage), new Scope(), $event);

        self::assertSame(['trace_id' => 'app-trace', 'span_id' => 'app-span'], $event->getTags());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function provideHalfPairs(): iterable
    {
        yield 'trace_id only' => [['trace_id' => 'app-trace']];
        yield 'span_id only' => [['span_id' => 'app-span']];
    }

    /**
     * @param array<string, string> $tags
     */
    #[DataProvider('provideHalfPairs')]
    public function testDoesNotCompleteAPairTheApplicationStarted(array $tags): void
    {
        $storage = new RequestIdService('shop', 'api');
        $storage->continueTrace($this->remoteTraceParent());

        $event = Event::createEvent();
        $event->setTags($tags);

        $event = $this->captureWith(new TracingIntegration($storage, $storage), new Scope(), $event);

        self::assertSame($tags, $event->getTags());
    }

    public function testEachUnitOfWorkTagsItsOwnTrace(): void
    {
        $storage = new RequestIdService('shop', 'api');
        $integration = new TracingIntegration($storage, $storage);
        $scope = new Scope();
        $storage->continueTrace($this->remoteTraceParent());
        $first = $this->captureWith($integration, $scope);

        $storage->reset();
        $second = $this->captureWith($integration, $scope);

        self::assertSame(self::TRACE_ID, $first->getTags()['trace_id'] ?? null);
        self::assertSame($storage->getTraceParent()->traceId, $second->getTags()['trace_id'] ?? null);
        self::assertSame($storage->getTraceParent()->parentId, $second->getTags()['span_id'] ?? null);
        self::assertNotSame(self::TRACE_ID, $second->getTags()['trace_id'] ?? null);
        self::assertNotSame($first->getTags()['span_id'] ?? null, $second->getTags()['span_id'] ?? null);
    }

    public function testRestoredOuterUnitTagsItsOwnTrace(): void
    {
        $storage = new RequestIdService('shop', 'api');
        $integration = new TracingIntegration($storage, $storage);
        $outer = $storage->getTraceParent();
        $snapshot = $storage->snapshot();
        $storage->reset();
        $storage->continueTrace($this->remoteTraceParent());

        $storage->restore($snapshot);

        $event = $this->captureWith($integration, new Scope());

        self::assertSame($outer->traceId, $event->getTags()['trace_id'] ?? null);
        self::assertSame($outer->parentId, $event->getTags()['span_id'] ?? null);
    }

    private function captureWith(TracingIntegration $integration, Scope $scope, ?Event $event = null): Event
    {
        $client = ClientBuilder::create([
            'integrations' => [$integration],
            'default_integrations' => false,
        ])->getClient();
        SentrySdk::init()->bindClient($client);

        $processed = $scope->applyToEvent($event ?? Event::createEvent());
        self::assertInstanceOf(Event::class, $processed);

        return $processed;
    }

    private function remoteTraceParent(): TraceParent
    {
        $traceParent = TraceParent::fromHeader('00-' . self::TRACE_ID . '-00f067aa0ba902b7-01');
        self::assertInstanceOf(TraceParent::class, $traceParent);

        return $traceParent;
    }
}
