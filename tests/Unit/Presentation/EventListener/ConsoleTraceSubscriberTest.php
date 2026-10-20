<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Presentation\EventListener;

use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use Msstc4Symfony\TracingBundle\Presentation\EventListener\ConsoleTraceSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;

#[CoversClass(ConsoleTraceSubscriber::class)]
#[UsesClass(InMemoryTraceContextHolder::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(Baggage::class)]
final class ConsoleTraceSubscriberTest extends TestCase
{
    public function testEveryCommandStartsItsOwnTrace(): void
    {
        $holder = new InMemoryTraceContextHolder();
        $before = $holder->current();

        (new ConsoleTraceSubscriber($holder))->onCommand();

        self::assertNotSame($before->runtimeId, $holder->current()->runtimeId);
        self::assertNotSame($before->traceParent->traceId, $holder->current()->traceParent->traceId);
        self::assertArrayHasKey(ConsoleEvents::COMMAND, ConsoleTraceSubscriber::getSubscribedEvents());
    }

    public function testListensBeforeTheCommandRunsAtAFixedPriority(): void
    {
        self::assertSame([ConsoleEvents::COMMAND => ['onCommand', 100]], ConsoleTraceSubscriber::getSubscribedEvents());
    }
}
