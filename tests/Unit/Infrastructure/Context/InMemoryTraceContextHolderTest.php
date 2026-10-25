<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Infrastructure\Context;

use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\SampleRatio;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryTraceContextHolder::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(Baggage::class)]
#[UsesClass(SampleRatio::class)]
final class InMemoryTraceContextHolderTest extends TestCase
{
    public function testHoldsAContextFromTheStart(): void
    {
        $holder = new InMemoryTraceContextHolder();

        self::assertSame($holder->current(), $holder->current());
    }

    public function testReplaceSwapsTheContext(): void
    {
        $holder = new InMemoryTraceContextHolder();
        $context = TraceContext::start();

        $holder->replace($context);

        self::assertSame($context, $holder->current());
    }

    public function testResetStartsANewUnitOfWork(): void
    {
        $holder = new InMemoryTraceContextHolder();
        $before = $holder->current();

        $holder->reset();

        self::assertNotSame($before->runtimeId, $holder->current()->runtimeId);
        self::assertNotSame($before->traceParent->traceId, $holder->current()->traceParent->traceId);
    }

    public function testResetStartsATraceWithTheConfiguredRatio(): void
    {
        $holder = new InMemoryTraceContextHolder(new SampleRatio(0.0));
        $holder->reset();

        self::assertSame(0, $holder->current()->traceParent->flags);
    }
}
