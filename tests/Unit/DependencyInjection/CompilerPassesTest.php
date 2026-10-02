<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\DependencyInjection;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use Msstc4Symfony\TracingBundle\DependencyInjection\Compiler\GuzzlePass;
use Msstc4Symfony\TracingBundle\DependencyInjection\Compiler\HttpClientPass;
use Msstc4Symfony\TracingBundle\GuzzleHttp\ChainedClientConfigurator;
use Msstc4Symfony\TracingBundle\GuzzleHttp\RequestIdGuzzleHandler;
use Msstc4Symfony\TracingBundle\HttpClient\HttpClientDecorator;
use Msstc4Symfony\TracingBundle\Test\Unit\DependencyInjection\Fixture\CustomConstructorClient;
use Msstc4Symfony\TracingBundle\Test\Unit\GuzzleHttp\Fixture\ClientWithoutConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(HttpClientPass::class)]
#[CoversClass(GuzzlePass::class)]
final class CompilerPassesTest extends TestCase
{
    public function testDecoratesOnlyTheSharedTransport(): void
    {
        if (!trait_exists(DecoratorTrait::class)) {
            self::markTestSkipped('symfony/http-client is not installed');
        }

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

    public function testWrapsTheHandlerOfGuzzleClientsWithoutTouchingOthers(): void
    {
        $container = $this->guzzleContainer();
        $container->setDefinition('github', new Definition(Client::class));
        $container->setDefinition('abstract', new Definition(Client::class)->setAbstract(true));
        $container->setDefinition('other', new Definition(stdClass::class));

        new GuzzlePass()->process($container);

        self::assertEquals([['handler' => $this->tracedHandler(null)]], $container->getDefinition('github')->getArguments());
        self::assertNull($container->getDefinition('github')->getConfigurator());
        self::assertSame([], $container->getDefinition('abstract')->getArguments());
        self::assertSame([], $container->getDefinition('other')->getArguments());
    }

    public function testKeepsTheClientConfigAndItsHandler(): void
    {
        $container = $this->guzzleContainer();
        $container->setDefinition('github', new Definition(Client::class, [['handler' => new Reference('app.stack'), 'timeout' => 3]]));

        new GuzzlePass()->process($container);

        self::assertEquals(
            [['handler' => $this->tracedHandler(new Reference('app.stack')), 'timeout' => 3]],
            $container->getDefinition('github')->getArguments(),
        );
    }

    public function testReadsANamedConfigArgument(): void
    {
        $container = $this->guzzleContainer();
        $container->setDefinition('github', new Definition(Client::class, ['$config' => ['timeout' => 3]]));

        new GuzzlePass()->process($container);

        self::assertEquals(
            ['$config' => ['timeout' => 3, 'handler' => $this->tracedHandler(null)]],
            $container->getDefinition('github')->getArguments(),
        );
    }

    public function testTracesAClientWithItsOwnConfiguratorAndKeepsIt(): void
    {
        $container = $this->guzzleContainer();
        $container->setDefinition('own', new Definition(Client::class)->setConfigurator(['app', 'configure']));

        new GuzzlePass()->process($container);

        self::assertSame(['app', 'configure'], $container->getDefinition('own')->getConfigurator());
        self::assertEquals([['handler' => $this->tracedHandler(null)]], $container->getDefinition('own')->getArguments());
    }

    public function testResolvesParameterisedClasses(): void
    {
        $container = $this->guzzleContainer();
        $container->setParameter('app.client.class', Client::class);
        $container->setDefinition('github', new Definition('%app.client.class%'));

        new GuzzlePass()->process($container);

        self::assertCount(1, $container->getDefinition('github')->getArguments());
    }

    public function testConfiguresAClientBuiltByAFactory(): void
    {
        $container = $this->guzzleContainer();
        $container->setDefinition('built', new Definition(Client::class, ['token'])->setFactory(['app.factory', 'create']));

        new GuzzlePass()->process($container);

        self::assertSame(['token'], $container->getDefinition('built')->getArguments());
        self::assertEquals([new Reference(RequestIdGuzzleHandler::class), 'addHandler'], $container->getDefinition('built')->getConfigurator());
    }

    public function testChainsTheOwnConfiguratorOfAClientBuiltByAFactory(): void
    {
        $container = $this->guzzleContainer();
        $container->setDefinition('built', new Definition(Client::class)->setFactory(['app.factory', 'create'])->setConfigurator(['app', 'configure']));

        new GuzzlePass()->process($container);

        $configurator = $container->getDefinition('built')->getConfigurator();
        self::assertIsArray($configurator);
        self::assertSame('__invoke', $configurator[1]);
        self::assertInstanceOf(Definition::class, $configurator[0]);
        self::assertSame(ChainedClientConfigurator::class, $configurator[0]->getClass());
        self::assertEquals([['app', 'configure'], new Reference(RequestIdGuzzleHandler::class)], $configurator[0]->getArguments());
    }

    public function testConfiguresAClientWhoseConfigIsAParameter(): void
    {
        $container = $this->guzzleContainer();
        $container->setDefinition('github', new Definition(Client::class, ['%app.guzzle_config%']));

        new GuzzlePass()->process($container);

        self::assertSame(['%app.guzzle_config%'], $container->getDefinition('github')->getArguments());
        self::assertEquals([new Reference(RequestIdGuzzleHandler::class), 'addHandler'], $container->getDefinition('github')->getConfigurator());
    }

    public function testConfiguresAClientWithItsOwnConstructor(): void
    {
        $container = $this->guzzleContainer();
        $container->setDefinition('custom', new Definition(CustomConstructorClient::class, ['token']));

        new GuzzlePass()->process($container);

        self::assertSame(['token'], $container->getDefinition('custom')->getArguments());
        self::assertEquals([new Reference(RequestIdGuzzleHandler::class), 'addHandler'], $container->getDefinition('custom')->getConfigurator());
    }

    public function testLeavesAClientItCannotReachAndLogsIt(): void
    {
        if (method_exists(ClientInterface::class, 'getConfig')) {
            self::markTestSkipped('Guzzle 7 clients always have getConfig()');
        }

        $container = $this->guzzleContainer();
        $container->setDefinition('custom', new Definition(ClientWithoutConfig::class)->setConfigurator(['app', 'configure']));

        new GuzzlePass()->process($container);

        self::assertSame(['app', 'configure'], $container->getDefinition('custom')->getConfigurator());
        self::assertNotEmpty(array_filter(
            $container->getCompiler()->getLog(),
            static fn (mixed $line): bool => is_string($line) && str_contains($line, 'Guzzle client "custom" is not traced'),
        ));
    }

    public function testHandlesEveryClientWhateverComesBefore(): void
    {
        $container = $this->guzzleContainer();
        $container->setDefinition('abstract', new Definition(Client::class)->setAbstract(true));
        $container->setDefinition('github', new Definition(Client::class));
        if (!method_exists(ClientInterface::class, 'getConfig')) {
            $container->setDefinition('unreachable', new Definition(ClientWithoutConfig::class));
        }
        $container->setDefinition('custom', new Definition(CustomConstructorClient::class, ['token']));
        $container->setDefinition('last', new Definition(Client::class));

        new GuzzlePass()->process($container);

        self::assertNotNull($container->getDefinition('custom')->getConfigurator());
        self::assertEquals([['handler' => $this->tracedHandler(null)]], $container->getDefinition('last')->getArguments());
    }

    public function testLeavesClientsAloneWithoutTheTracingService(): void
    {
        $container = $this->guzzleContainer();
        $container->removeDefinition(RequestIdGuzzleHandler::class);
        $container->setDefinition('github', new Definition(Client::class));

        new GuzzlePass()->process($container);

        self::assertSame([], $container->getDefinition('github')->getArguments());
    }

    public function testSurvivesClassesWithAMissingParent(): void
    {
        // Generated at run time: a source file extending a missing class would fail static analysis.
        $class = 'Msstc4Symfony\\TracingBundle\\Test\\Generated\\OrphanClient';
        $file = sys_get_temp_dir() . '/msstc4symfony-tracing-orphan-client-' . getmypid() . '.php';
        file_put_contents($file, "<?php\nnamespace Msstc4Symfony\\TracingBundle\\Test\\Generated;\nfinal class OrphanClient extends \\Not\\Installed\\BaseClient {}\n");
        $autoload = static function (string $name) use ($class, $file): void {
            if ($name === $class) {
                require $file;
            }
        };
        spl_autoload_register($autoload);

        try {
            $container = $this->guzzleContainer();
            $container->setDefinition('orphan', new Definition($class));

            new GuzzlePass()->process($container);

            self::assertNull($container->getDefinition('orphan')->getConfigurator());
            self::assertSame([], $container->getDefinition('orphan')->getArguments());
        } finally {
            spl_autoload_unregister($autoload);
            unlink($file);
        }
    }

    private function guzzleContainer(): ContainerBuilder
    {
        if (!interface_exists(ClientInterface::class)) {
            self::markTestSkipped('guzzlehttp/guzzle is not installed');
        }

        $container = new ContainerBuilder();
        $container->setDefinition(RequestIdGuzzleHandler::class, new Definition(RequestIdGuzzleHandler::class));

        return $container;
    }

    private function tracedHandler(?Reference $handler): Definition
    {
        return new Definition(HandlerStack::class, [$handler])
            ->setFactory([new Reference(RequestIdGuzzleHandler::class), 'decorateHandler'])
        ;
    }
}
