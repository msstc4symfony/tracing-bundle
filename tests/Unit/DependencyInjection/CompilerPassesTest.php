<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\DependencyInjection;

use GuzzleHttp\Client;
use Msstc4Symfony\TracingBundle\DependencyInjection\Compiler\GuzzlePass;
use Msstc4Symfony\TracingBundle\DependencyInjection\Compiler\HttpClientPass;
use Msstc4Symfony\TracingBundle\GuzzleHttp\RequestIdGuzzleHandler;
use Msstc4Symfony\TracingBundle\HttpClient\HttpClientDecorator;
use Msstc4Symfony\TracingBundle\Test\Unit\DependencyInjection\Fixture\OrphanClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(HttpClientPass::class)]
#[CoversClass(GuzzlePass::class)]
final class CompilerPassesTest extends TestCase
{
    public function testDecoratesOnlyTheSharedTransport(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(HttpClientPass::TRANSPORT_ID, new Definition(HttpClientInterface::class));
        $container->setDefinition('http_client', new Definition(HttpClientInterface::class));

        new HttpClientPass()->process($container);

        $decorator = $container->getDefinition(HttpClientPass::DECORATOR_ID);
        self::assertSame(HttpClientDecorator::class, $decorator->getClass());
        self::assertSame([HttpClientPass::TRANSPORT_ID, null, -15], $decorator->getDecoratedService());
        self::assertCount(1, array_filter(
            $container->getDefinitions(),
            static fn (Definition $definition): bool => $definition->getDecoratedService() !== null,
        ));
    }

    public function testNoTransportNoDecorator(): void
    {
        $container = new ContainerBuilder();

        new HttpClientPass()->process($container);

        self::assertFalse($container->hasDefinition(HttpClientPass::DECORATOR_ID));
    }

    public function testConfiguresGuzzleClientsWithoutTouchingOthers(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(RequestIdGuzzleHandler::class, new Definition(RequestIdGuzzleHandler::class));
        $container->setDefinition('github', new Definition(Client::class));
        $container->setDefinition('own', new Definition(Client::class)->setConfigurator(['app', 'configure']));
        $container->setDefinition('abstract', new Definition(Client::class)->setAbstract(true));
        $container->setDefinition('other', new Definition(stdClass::class));

        new GuzzlePass()->process($container);

        self::assertEquals(
            [new Reference(RequestIdGuzzleHandler::class), 'addHandler'],
            $container->getDefinition('github')->getConfigurator(),
        );
        self::assertSame(['app', 'configure'], $container->getDefinition('own')->getConfigurator());
        self::assertNull($container->getDefinition('abstract')->getConfigurator());
        self::assertNull($container->getDefinition('other')->getConfigurator());
    }

    public function testResolvesParameterisedClasses(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('app.client.class', Client::class);
        $container->setDefinition(RequestIdGuzzleHandler::class, new Definition(RequestIdGuzzleHandler::class));
        $container->setDefinition('github', new Definition('%app.client.class%'));

        new GuzzlePass()->process($container);

        self::assertNotNull($container->getDefinition('github')->getConfigurator());
    }

    public function testSurvivesClassesWithAMissingParent(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(RequestIdGuzzleHandler::class, new Definition(RequestIdGuzzleHandler::class));
        $container->setDefinition('orphan', new Definition(OrphanClient::class));

        new GuzzlePass()->process($container);

        self::assertNull($container->getDefinition('orphan')->getConfigurator());
    }
}
