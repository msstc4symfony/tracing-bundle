<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\DependencyInjection;

use InvalidArgumentException;
use Msstc4Symfony\TracingBundle\Application\BaggageRecipients;
use Msstc4Symfony\TracingBundle\DependencyInjection\CompilerPass\BaggageRecipientsPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(BaggageRecipientsPass::class)]
#[UsesClass(BaggageRecipients::class)]
final class BaggageRecipientsPassTest extends TestCase
{
    public function testRejectsAnInvalidHost(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(BaggageRecipientsPass::PARAMETER, ['orders', 'orders:8080']);

        try {
            (new BaggageRecipientsPass())->process($container);
            self::fail('An invalid host passed.');
        } catch (InvalidConfigurationException $exception) {
            self::assertSame('Invalid configuration for path "msstc4symfony_tracing.baggage.allowed_hosts": "orders:8080" is not a host, an IP address or "*.domain".', $exception->getMessage());
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        }
    }

    public function testRejectsAnInvalidHostFromAParameter(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('hosts.internal', 'bad host');
        $container->setParameter(BaggageRecipientsPass::PARAMETER, ['%hosts.internal%']);

        $this->expectException(InvalidConfigurationException::class);

        (new BaggageRecipientsPass())->process($container);
    }

    public function testRejectsAHostParameterThatIsNotAString(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('hosts.port', 8080);
        $container->setParameter(BaggageRecipientsPass::PARAMETER, ['orders', '%hosts.port%']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid configuration for path "msstc4symfony_tracing.baggage.allowed_hosts": 8080 is not a string.');

        (new BaggageRecipientsPass())->process($container);
    }

    #[DoesNotPerformAssertions]
    public function testLeavesAnEntryBuiltFromAnEnvVariableToRuntime(): void
    {
        $container = new ContainerBuilder();
        // Not a host as written: only the variable's value is.
        $container->setParameter(BaggageRecipientsPass::PARAMETER, ['%env(ORDERS_HOST)%:x', 'orders']);

        (new BaggageRecipientsPass())->process($container);
    }

    /**
     * @param list<string>|string|null $hosts
     */
    #[DoesNotPerformAssertions]
    #[TestWith([null])]
    #[TestWith([[]])]
    #[TestWith([['orders', '*.example.com', '[::1]']])]
    // Guard only: the config tree already refuses a whole list from one %env()%.
    #[TestWith(['%env(json:HOSTS)%'])]
    public function testAcceptsValidHosts(array|string|null $hosts): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(BaggageRecipientsPass::PARAMETER, $hosts);

        (new BaggageRecipientsPass())->process($container);
    }

    #[DoesNotPerformAssertions]
    public function testAcceptsAnEnvPlaceholderEntry(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(BaggageRecipientsPass::PARAMETER, [$container->getParameterBag()->resolveValue('%env(ORDERS_HOST)%')]);

        (new BaggageRecipientsPass())->process($container);
    }

    #[DoesNotPerformAssertions]
    public function testDoesNothingWithoutTheParameter(): void
    {
        (new BaggageRecipientsPass())->process(new ContainerBuilder());
    }
}
