<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Framework\Sentry;

use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Framework\Sentry\TracingIntegration;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
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
#[UsesClass(InMemoryTraceContextHolder::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(Baggage::class)]
final class TracingIntegrationTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

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
        $holder = new InMemoryTraceContextHolder();
        $holder->replace($holder->current()->withBaggage(Baggage::fromHeaders(['msstc4symfony.caller=billing%3Aapi'])));

        $event = $this->captureWith(new TracingIntegration($holder), new Scope());

        self::assertSame('billing:api', $event->getExtra()['caller'] ?? null);
        self::assertSame($holder->current()->runtimeId, $event->getExtra()['runtime_id'] ?? null);
    }

    public function testTagsEventsWithTheW3cTrace(): void
    {
        $storage = $this->continuedHolder();

        $event = Event::createEvent();
        $event->setTags(['env' => 'prod']);

        $event = $this->captureWith(new TracingIntegration($storage), new Scope(), $event);

        self::assertSame(
            ['env' => 'prod', 'trace_id' => self::TRACE_ID, 'span_id' => $storage->current()->traceParent->spanId],
            $event->getTags(),
        );
    }

    public function testLeavesSentryTraceContextToSentry(): void
    {
        $storage = $this->continuedHolder();

        $scope = new Scope();

        $event = $this->captureWith(new TracingIntegration($storage), $scope);

        $sentryTrace = $event->getContexts()['trace'] ?? [];
        self::assertSame((string) $scope->getPropagationContext()->getTraceId(), $sentryTrace['trace_id'] ?? null);
        self::assertNotSame(self::TRACE_ID, $sentryTrace['trace_id'] ?? null);
    }

    public function testKeepsTagsTheApplicationSetOnTheEvent(): void
    {
        $storage = $this->continuedHolder();

        $event = Event::createEvent();
        $event->setTags(['trace_id' => 'app-trace', 'span_id' => 'app-span']);

        $event = $this->captureWith(new TracingIntegration($storage), new Scope(), $event);

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
        $storage = $this->continuedHolder();

        $event = Event::createEvent();
        $event->setTags($tags);

        $event = $this->captureWith(new TracingIntegration($storage), new Scope(), $event);

        self::assertSame($tags, $event->getTags());
    }

    public function testEachUnitOfWorkTagsItsOwnTrace(): void
    {
        $storage = $this->continuedHolder();
        $integration = new TracingIntegration($storage);
        $scope = new Scope();
        $first = $this->captureWith($integration, $scope);

        $storage->reset();
        $second = $this->captureWith($integration, $scope);

        self::assertSame(self::TRACE_ID, $first->getTags()['trace_id'] ?? null);
        self::assertSame($storage->current()->traceParent->traceId, $second->getTags()['trace_id'] ?? null);
        self::assertSame($storage->current()->traceParent->spanId, $second->getTags()['span_id'] ?? null);
        self::assertNotSame(self::TRACE_ID, $second->getTags()['trace_id'] ?? null);
        self::assertNotSame($first->getTags()['span_id'] ?? null, $second->getTags()['span_id'] ?? null);
    }

    public function testRestoredOuterUnitTagsItsOwnTrace(): void
    {
        $storage = new InMemoryTraceContextHolder();
        $integration = new TracingIntegration($storage);
        $snapshot = $storage->current();
        $outer = $snapshot->traceParent;
        $storage->replace(TraceContext::start()->continuing($this->remoteTraceParent(), null, Baggage::empty()));

        $storage->replace($snapshot);

        $event = $this->captureWith($integration, new Scope());

        self::assertSame($outer->traceId, $event->getTags()['trace_id'] ?? null);
        self::assertSame($outer->spanId, $event->getTags()['span_id'] ?? null);
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

    private function continuedHolder(): InMemoryTraceContextHolder
    {
        $holder = new InMemoryTraceContextHolder();
        $holder->replace($holder->current()->continuing($this->remoteTraceParent(), null, Baggage::empty()));

        return $holder;
    }

    private function remoteTraceParent(): TraceParent
    {
        $traceParent = TraceParent::fromHeader('00-' . self::TRACE_ID . '-00f067aa0ba902b7-01');
        self::assertInstanceOf(TraceParent::class, $traceParent);

        return $traceParent;
    }
}
