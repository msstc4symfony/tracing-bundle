<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Monolog;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Msstc4Symfony\TracingBundle\Monolog\RequestIdProcessor;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestIdProcessor::class)]
#[UsesClass(RequestIdService::class)]
#[UsesClass(TraceParent::class)]
final class RequestIdProcessorTest extends TestCase
{
    public function testAddsTheTraceAndKeepsExistingExtra(): void
    {
        $storage = new RequestIdService('shop', 'api')->setRequestId('abc')->setRequestFrom('billing:worker');
        $record = new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'hello', extra: ['user' => 7]);

        $extra = new RequestIdProcessor($storage)($record)->extra;

        self::assertSame(
            ['user' => 7, 'runtime_id' => $storage->getRuntimeId(), 'request_id' => 'abc', 'request_from' => 'billing:worker'],
            $extra,
        );
    }

    public function testAddsTheW3cTraceWhenEnabled(): void
    {
        $storage = new RequestIdService('shop', 'api')->setRequestId('abc');
        $record = new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'hello');

        $extra = new RequestIdProcessor($storage, $storage)($record)->extra;

        self::assertSame($storage->getTraceParent()->traceId, $extra['trace_id'] ?? null);
        self::assertSame($storage->getTraceParent()->parentId, $extra['span_id'] ?? null);
    }
}
