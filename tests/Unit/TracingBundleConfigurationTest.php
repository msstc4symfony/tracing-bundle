<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit;

use Msstc4Symfony\TracingBundle\TracingBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
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

    public function testEveryTraceIsSampledByDefault(): void
    {
        self::assertSame(['ratio' => 1.0], $this->sampling([[]]));
    }

    public function testAcceptsARatioBetweenZeroAndOne(): void
    {
        self::assertSame(['ratio' => 0.25], $this->sampling([['sampling' => ['ratio' => 0.25]]]));
    }

    public function testAcceptsAnIntegerRatio(): void
    {
        self::assertSame(['ratio' => 0.0], $this->sampling([['sampling' => ['ratio' => 0]]]));
    }

    #[TestWith([-0.1])]
    #[TestWith([1.5])]
    public function testRejectsARatioOutsideZeroToOne(float $ratio): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->sampling([['sampling' => ['ratio' => $ratio]]]);
    }

    /**
     * @param list<array<string, mixed>> $configs
     */
    private function allowedHosts(array $configs): mixed
    {
        $config = $this->process($configs);
        self::assertIsArray($config['baggage'] ?? null);

        return $config['baggage']['allowed_hosts'] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $configs
     */
    private function sampling(array $configs): mixed
    {
        return $this->process($configs)['sampling'] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $configs
     *
     * @return array<array-key, mixed>
     */
    private function process(array $configs): array
    {
        $container = new ContainerBuilder();
        $extension = (new TracingBundle())->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], $container);
        self::assertInstanceOf(ConfigurationInterface::class, $configuration);

        return (new Processor())->processConfiguration($configuration, $configs);
    }
}
