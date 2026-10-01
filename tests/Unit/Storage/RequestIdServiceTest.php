<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Storage;

use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RequestIdService::class)]
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
}
