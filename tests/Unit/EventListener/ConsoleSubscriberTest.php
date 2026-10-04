<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\EventListener;

use Msstc4Symfony\TracingBundle\EventListener\ConsoleSubscriber;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;

#[CoversClass(ConsoleSubscriber::class)]
#[UsesClass(RequestIdService::class)]
final class ConsoleSubscriberTest extends TestCase
{
    public function testEveryCommandStartsItsOwnTrace(): void
    {
        $storage = new RequestIdService('shop', 'cli')->setRequestId('previous');
        $runtimeId = $storage->getRuntimeId();

        new ConsoleSubscriber($storage)->onCommand();

        self::assertNotSame('previous', $storage->getRequestId());
        self::assertNotSame($runtimeId, $storage->getRuntimeId());
        self::assertArrayHasKey(ConsoleEvents::COMMAND, ConsoleSubscriber::getSubscribedEvents());
    }
}
