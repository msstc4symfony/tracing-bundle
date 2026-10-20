<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Framework\Monolog;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\RuntimeId;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Framework\Monolog\TraceLogProcessor;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TraceLogProcessor::class)]
#[UsesClass(InMemoryTraceContextHolder::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(RuntimeId::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(Baggage::class)]
final class TraceLogProcessorTest extends TestCase
{
    public function testAddsTheTraceAndKeepsExistingExtra(): void
    {
        $holder = new InMemoryTraceContextHolder();
        $record = new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'hello', extra: ['user' => 7]);

        $extra = (new TraceLogProcessor($holder))($record)->extra;

        $context = $holder->current();
        self::assertSame(
            [
                'user' => 7,
                'runtime_id' => $context->runtimeId,
                'trace_id' => $context->traceParent->traceId,
                'span_id' => $context->traceParent->spanId,
                'parent_span_id' => null,
                'caller' => null,
            ],
            $extra,
        );
    }

    public function testAddsTheParentSpanAndTheCallerButNotTheRestOfTheBaggage(): void
    {
        $received = TraceParent::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        self::assertInstanceOf(TraceParent::class, $received);
        $holder = new InMemoryTraceContextHolder();
        $holder->replace($holder->current()->continuing($received, null, Baggage::fromHeaders(['msstc4symfony.caller=gateway%3Aweb,secret=s3cr3t'])));

        $record = new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'hello');

        $extra = (new TraceLogProcessor($holder))($record)->extra;

        self::assertSame('00f067aa0ba902b7', $extra['parent_span_id'] ?? null);
        self::assertSame('gateway:web', $extra['caller'] ?? null);
        self::assertStringNotContainsString('s3cr3t', (string) json_encode($extra));
    }
}
