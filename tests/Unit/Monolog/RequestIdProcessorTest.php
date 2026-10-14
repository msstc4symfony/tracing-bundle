<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Monolog;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Msstc4Symfony\TracingBundle\Monolog\RequestIdProcessor;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestIdProcessor::class)]
#[UsesClass(RequestIdService::class)]
#[UsesClass(TraceContext::class)]
#[UsesClass(TraceParent::class)]
final class RequestIdProcessorTest extends TestCase
{
    public function testAddsTheTraceAndKeepsExistingExtra(): void
    {
        $storage = (new RequestIdService('shop', 'api'))->setRequestId('abc')->setRequestFrom('billing:worker');
        $record = new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'hello', extra: ['user' => 7]);

        $extra = (new RequestIdProcessor($storage))($record)->extra;

        self::assertSame(
            [
                'user' => 7,
                'runtime_id' => $storage->getRuntimeId(),
                'request_id' => 'abc',
                'request_from' => 'billing:worker',
                'trace_id' => $storage->getTraceParent()->traceId,
                'span_id' => $storage->getTraceParent()->parentId,
                'parent_span_id' => null,
            ],
            $extra,
        );
    }

    public function testAddsTheParentSpanOfAContinuedTrace(): void
    {
        $received = TraceParent::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        self::assertInstanceOf(TraceParent::class, $received);
        $storage = (new RequestIdService('shop', 'api'))->continueTrace($received);
        $record = new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'hello');

        $extra = (new RequestIdProcessor($storage))($record)->extra;

        self::assertSame('00f067aa0ba902b7', $extra['parent_span_id'] ?? null);
    }
}
