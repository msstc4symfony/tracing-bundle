<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Storage;

use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RequestIdService::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
final class RequestIdServiceTest extends TestCase
{
    public function testGeneratesRequestContextLazilyAndKeepsIt(): void
    {
        $service = new RequestIdService('shop', 'api');

        $requestId = $service->getRequestId();

        self::assertTrue(Uuid::isValid($requestId));
        self::assertSame($requestId, $service->getRequestId());
        self::assertSame('shop:api', $service->getRequestFrom());
    }

    public function testKeepsReceivedContext(): void
    {
        $service = new RequestIdService('shop', 'api')->setRequestId('abc')->setRequestFrom('billing:worker');

        self::assertSame('abc', $service->getRequestId());
        self::assertSame('billing:worker', $service->getRequestFrom());
        self::assertSame('shop:api', $service->getCurrentRequestFrom());
    }

    public function testResetStartsANewRuntimeAndForgetsTheRequest(): void
    {
        $service = new RequestIdService('shop', 'api')->setRequestId('abc');
        $runtimeId = $service->getRuntimeId();

        $service->reset();

        self::assertNotSame($runtimeId, $service->getRuntimeId());
        self::assertNotSame('abc', $service->getRequestId());
    }

    public function testGenerateReplacesTheContext(): void
    {
        $service = new RequestIdService('shop', 'api')->setRequestId('abc')->setRequestFrom('other');

        $service->generate();

        self::assertNotSame('abc', $service->getRequestId());
        self::assertSame('shop:api', $service->getRequestFrom());
    }

    public function testStartsAW3cTraceLazilyAndKeepsIt(): void
    {
        $service = new RequestIdService('shop', 'api');

        $traceParent = $service->getTraceParent();

        self::assertSame(TraceParent::FLAG_SAMPLED, $traceParent->flags);
        self::assertSame($traceParent, $service->getTraceParent());
        self::assertNull($service->getRemoteTraceParent());
        self::assertNull($service->getTraceState());
    }

    public function testContinuesAReceivedTraceWithItsOwnSpan(): void
    {
        $service = new RequestIdService('shop', 'api');
        $received = $this->traceParent();
        $state = TraceState::fromHeaders(['rojo=1']);

        $service->continueTrace($received, $state);

        self::assertSame($received, $service->getRemoteTraceParent());
        self::assertSame($state, $service->getTraceState());
        self::assertSame($received->traceId, $service->getTraceParent()->traceId);
        self::assertSame($received->flags, $service->getTraceParent()->flags);
        self::assertNotSame($received->parentId, $service->getTraceParent()->parentId);
    }

    public function testOutgoingTraceParentIsANewChildOfThisUnitEveryTime(): void
    {
        $service = new RequestIdService('shop', 'api');
        $service->continueTrace($this->traceParent());

        $first = $service->createOutgoingTraceParent();
        $second = $service->createOutgoingTraceParent();

        self::assertSame($service->getTraceParent()->traceId, $first->traceId);
        self::assertSame($service->getTraceParent()->traceId, $second->traceId);
        self::assertNotSame($first->parentId, $second->parentId);
        self::assertNotSame($service->getTraceParent()->parentId, $first->parentId);
    }

    public function testResetForgetsTheW3cTrace(): void
    {
        $service = new RequestIdService('shop', 'api');
        $received = $this->traceParent();
        $service->continueTrace($received, TraceState::fromHeaders(['rojo=1']));

        $service->reset();

        self::assertNull($service->getRemoteTraceParent());
        self::assertNull($service->getTraceState());
        self::assertNotSame($received->traceId, $service->getTraceParent()->traceId);
    }

    public function testRestoreBringsBackTheW3cTrace(): void
    {
        $service = new RequestIdService('shop', 'api');
        $received = $this->traceParent();
        $state = TraceState::fromHeaders(['rojo=1']);
        $service->continueTrace($received, $state);
        $own = $service->getTraceParent();
        $snapshot = $service->snapshot();

        $service->reset();
        $service->getTraceParent();
        $service->restore($snapshot);

        self::assertSame($received, $service->getRemoteTraceParent());
        self::assertSame($state, $service->getTraceState());
        self::assertSame($own, $service->getTraceParent());
    }

    public function testRestoringAnOlderSnapshotStartsAFreshW3cTrace(): void
    {
        $service = new RequestIdService('shop', 'api');
        $service->continueTrace($this->traceParent());

        $traceId = $service->getTraceParent()->traceId;

        $service->restore(new TraceContext('runtime', 'abc', 'billing:api'));

        self::assertNull($service->getRemoteTraceParent());
        self::assertNotSame($traceId, $service->getTraceParent()->traceId);
    }

    private function traceParent(): TraceParent
    {
        $traceParent = TraceParent::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00');
        self::assertInstanceOf(TraceParent::class, $traceParent);

        return $traceParent;
    }
}
