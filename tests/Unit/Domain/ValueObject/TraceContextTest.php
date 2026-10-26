<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Domain\ValueObject;

use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\OutgoingTrace;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\SampleRatio;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TraceContext::class)]
#[CoversClass(OutgoingTrace::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
#[UsesClass(Baggage::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(SampleRatio::class)]
final class TraceContextTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    private const RECEIVED = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    public function testStartsARootTraceWithAFreshRuntimeId(): void
    {
        $first = TraceContext::start();
        $second = TraceContext::start();

        self::assertMatchesRegularExpression(self::UUID_V4, $first->runtimeId);
        self::assertNotSame($first->runtimeId, $second->runtimeId);
        self::assertNotSame($first->traceParent->traceId, $second->traceParent->traceId);
        self::assertNull($first->remoteTraceParent);
        self::assertNull($first->traceState);
        self::assertNull($first->parentSpanId);
        self::assertTrue($first->baggage->isEmpty());
    }

    public function testContinuingJoinsTheCallersTraceAndKeepsTheRuntimeId(): void
    {
        $received = TraceParent::fromHeader(self::RECEIVED);
        self::assertInstanceOf(TraceParent::class, $received);
        $state = TraceState::fromHeaders(['vendor=value']);
        $baggage = Baggage::fromHeaders(['a=1']);
        $start = TraceContext::start();

        $context = $start->continuing($received, $state, $baggage);

        self::assertSame($start->runtimeId, $context->runtimeId);
        self::assertSame($received->traceId, $context->traceParent->traceId);
        self::assertNotSame($received->spanId, $context->traceParent->spanId);
        self::assertSame($received, $context->remoteTraceParent);
        self::assertSame($received->spanId, $context->parentSpanId);
        self::assertSame($state, $context->traceState);
        self::assertSame($baggage, $context->baggage);
    }

    public function testWithBaggageChangesOnlyTheBaggage(): void
    {
        $start = TraceContext::start();
        $baggage = Baggage::fromHeaders(['a=1']);

        $context = $start->withBaggage($baggage);

        self::assertSame($baggage, $context->baggage);
        self::assertSame($start->runtimeId, $context->runtimeId);
        self::assertSame($start->traceParent, $context->traceParent);
        self::assertNull($context->parentSpanId);
    }

    public function testChildSpanStaysInTheTraceAndUnitWithThisSpanAsParent(): void
    {
        $received = TraceParent::fromHeader(self::RECEIVED);
        self::assertInstanceOf(TraceParent::class, $received);
        $outer = TraceContext::start()->continuing($received, null, Baggage::fromHeaders(['a=1']));

        $child = $outer->withChildSpan();

        self::assertSame($outer->runtimeId, $child->runtimeId);
        self::assertSame($outer->traceParent->traceId, $child->traceParent->traceId);
        self::assertNotSame($outer->traceParent->spanId, $child->traceParent->spanId);
        self::assertSame($outer->traceParent->spanId, $child->parentSpanId);
        self::assertSame($outer->remoteTraceParent, $child->remoteTraceParent);
        self::assertSame($outer->baggage, $child->baggage);
    }

    public function testCallerComesFromTheReceivedBaggage(): void
    {
        self::assertNull(TraceContext::start()->caller());
        self::assertSame(
            'shop:web',
            TraceContext::start()->withBaggage(Baggage::fromHeaders(['msstc4symfony.caller=shop%3Aweb']))->caller(),
        );
    }

    public function testOutgoingSendsThisSpanAndNamesThisServiceAsTheCaller(): void
    {
        $state = TraceState::fromHeaders(['vendor=value']);
        $received = TraceParent::fromHeader(self::RECEIVED);
        self::assertInstanceOf(TraceParent::class, $received);
        $context = TraceContext::start()->continuing(
            $received,
            $state,
            Baggage::fromHeaders(['msstc4symfony.caller=gateway%3Aweb,user=alice']),
        );

        $outgoing = $context->outgoing('orders:api');

        self::assertSame($context->traceParent, $outgoing->traceParent);
        self::assertSame($state, $outgoing->traceState);
        self::assertSame('orders:api', $outgoing->baggage->get(TraceContext::CALLER_KEY));
        self::assertSame('alice', $outgoing->baggage->get('user'));
        self::assertSame('gateway:web', $context->caller());
    }

    public function testStartPassesTheRatioToTheNewTrace(): void
    {
        self::assertSame(TraceParent::FLAG_RANDOM, TraceContext::start(new SampleRatio(0.0))->traceParent->flags);
    }

    public function testATraceSampledByARatioCarriesItsThresholdInTraceState(): void
    {
        // 1 - 2^-53: threshold 8, so a trace id is dropped with a chance of 2^-53.
        $context = TraceContext::start(new SampleRatio(1 - 2 ** -53));

        self::assertTrue($context->traceParent->isSampled());
        self::assertSame('ot=th:00000000000008', $context->traceState?->value);
    }

    public function testATraceTheRatioDropsCarriesNoThreshold(): void
    {
        // 2^-56: a trace id is sampled with a chance of 2^-56, and the threshold has a th form.
        $context = TraceContext::start(new SampleRatio(2 ** -56));

        self::assertFalse($context->traceParent->isSampled());
        self::assertNull($context->traceState);
        self::assertNull(TraceContext::start(new SampleRatio(0.0))->traceState);
    }

    public function testARatioOfOneSendsNoTraceState(): void
    {
        // As without sampling configured: the wire stays as before 2.3.
        self::assertNull(TraceContext::start(new SampleRatio(1.0))->traceState);
        self::assertNull(TraceContext::start()->traceState);
    }

    public function testAContinuedTraceKeepsTheReceivedFlagWhateverTheRatio(): void
    {
        $received = TraceParent::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        self::assertNotNull($received);

        $context = TraceContext::start(new SampleRatio(0.0))->continuing($received, null, Baggage::empty());

        self::assertSame(TraceParent::FLAG_SAMPLED, $context->traceParent->flags);
    }
}
