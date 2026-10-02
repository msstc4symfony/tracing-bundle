<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\DependencyInjection;

use Msstc4Symfony\TracingBundle\DependencyInjection\Compiler\W3cTraceContextPass;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\W3cTraceContextInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(W3cTraceContextPass::class)]
final class W3cTraceContextPassTest extends TestCase
{
    public function testFollowsAnApplicationStorageImplementingBothInterfaces(): void
    {
        $storage = self::createStubForIntersectionOfInterfaces([RequestIdServiceInterface::class, W3cTraceContextInterface::class]);
        $container = $this->container($storage::class, w3c: true);

        new W3cTraceContextPass()->process($container);

        self::assertSame('app.storage', (string) $container->getAlias(RequestIdServiceInterface::class));
        self::assertSame(RequestIdServiceInterface::class, (string) $container->getAlias(W3cTraceContextInterface::class));
        self::assertTrue($container->getAlias(W3cTraceContextInterface::class)->isPublic());
    }

    public function testKeepsTheBundleStorageWhenTheApplicationOneHasNoW3c(): void
    {
        $storage = self::createStub(RequestIdServiceInterface::class);
        $container = $this->container($storage::class, w3c: true);

        new W3cTraceContextPass()->process($container);

        self::assertSame(RequestIdService::class, (string) $container->getAlias(W3cTraceContextInterface::class));
    }

    public function testIgnoresAStorageWhoseClassCannotBeLoaded(): void
    {
        $container = $this->container('App\\Missing\\Storage', w3c: true);

        new W3cTraceContextPass()->process($container);

        self::assertSame(RequestIdService::class, (string) $container->getAlias(W3cTraceContextInterface::class));
    }

    public function testLeavesW3cOffWhenDisabled(): void
    {
        $storage = self::createStubForIntersectionOfInterfaces([RequestIdServiceInterface::class, W3cTraceContextInterface::class]);
        $container = $this->container($storage::class, w3c: false);

        new W3cTraceContextPass()->process($container);

        self::assertFalse($container->has(W3cTraceContextInterface::class));
    }

    /**
     * @param string $storageClass not necessarily loadable
     */
    private function container(string $storageClass, bool $w3c): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(RequestIdService::class, RequestIdService::class);
        $container->register('app.storage', $storageClass);
        $container->setAlias(RequestIdServiceInterface::class, 'app.storage')->setPublic(true);
        if ($w3c) {
            $container->setAlias(W3cTraceContextInterface::class, RequestIdService::class)->setPublic(true);
        }

        return $container;
    }
}
