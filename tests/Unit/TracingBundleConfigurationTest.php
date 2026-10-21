<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit;

use Msstc4Symfony\TracingBundle\TracingBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;

#[CoversClass(TracingBundle::class)]
final class TracingBundleConfigurationTest extends TestCase
{
    public function testEveryHostGetsBaggageByDefault(): void
    {
        self::assertNull($this->allowedHosts([]));
    }

    public function testALaterFileReplacesTheAllowedHosts(): void
    {
        self::assertSame(['orders'], $this->allowedHosts([
            ['baggage' => ['allowed_hosts' => ['orders', 'payments']]],
            ['baggage' => ['allowed_hosts' => ['orders']]],
        ]));
    }

    public function testAnEmptyListAllowsNoHost(): void
    {
        self::assertSame([], $this->allowedHosts([['baggage' => ['allowed_hosts' => []]]]));
    }

    public function testReadsTheXmlSingular(): void
    {
        self::assertSame(['orders'], $this->allowedHosts([['baggage' => ['allowed_host' => 'orders']]]));
    }

    /**
     * @param list<array<string, mixed>> $configs
     */
    private function allowedHosts(array $configs): mixed
    {
        $container = new ContainerBuilder();
        $extension = (new TracingBundle())->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], $container);
        self::assertInstanceOf(ConfigurationInterface::class, $configuration);

        $config = (new Processor())->processConfiguration($configuration, $configs);
        self::assertIsArray($config['baggage'] ?? null);

        return $config['baggage']['allowed_hosts'] ?? null;
    }
}
