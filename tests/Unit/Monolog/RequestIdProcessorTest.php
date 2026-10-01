<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Monolog;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Msstc4Symfony\TracingBundle\Monolog\RequestIdProcessor;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestIdProcessor::class)]
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
}
