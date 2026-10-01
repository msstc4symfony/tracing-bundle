<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration\Kernel;

use GuzzleHttp\Client;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\IncomingStampMiddleware;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\OutgoingStampMiddleware;
use Msstc4Symfony\TracingBundle\TracingBundle;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string GUZZLE_CLIENT = 'test.guzzle';

    public const string GUZZLE_CHILD_CLIENT = 'test.guzzle.child';

    public const string GUZZLE_CONSUMER = 'test.guzzle.consumer';

    // Per process: infection runs PHPUnit in parallel and setUp() wipes this directory.
    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-tracing-bundle-test-' . getmypid();
    }

    #[Override]
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new MonologBundle(), new TracingBundle()];
    }

    #[Override]
    public function getCacheDir(): string
    {
        return self::cacheRoot() . '/cache/' . $this->environment;
    }

    #[Override]
    public function getLogDir(): string
    {
        return self::cacheRoot() . '/log';
    }

    public function ping(): Response
    {
        return new Response('pong');
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'http_method_override' => false,
            // Symfony 6.4 deprecates leaving it unset.
            'handle_all_throwables' => true,
            'test' => true,
            'router' => ['utf8' => true],
            // The php_errors logger installs a global handler that outlives the kernel and trips failOnRisky.
            'php_errors' => ['log' => false],
            'http_client' => ['mock_response_factory' => RecordingResponseFactory::class],
            'messenger' => [
                'default_bus' => 'bus.commands',
                'buses' => [
                    'bus.commands' => ['middleware' => [OutgoingStampMiddleware::class, IncomingStampMiddleware::class]],
                    'bus.events' => ['middleware' => [OutgoingStampMiddleware::class, IncomingStampMiddleware::class]],
                ],
            ],
        ]);
        $container->extension('monolog', [
            'handlers' => ['main' => ['type' => 'test']],
        ]);

        $services = $container->services();
        $services->set(RecordingResponseFactory::class)->public();
        $services->set(self::GUZZLE_CLIENT, Client::class)->public();
        // Class known only after child definitions are resolved.
        $services->set('test.guzzle.parent', Client::class)->abstract();
        $services->set(self::GUZZLE_CHILD_CLIENT)->parent('test.guzzle.parent')->public();
        // A private client injected into another service, as most applications wire Guzzle.
        $services->set('test.guzzle.inlined', Client::class);
        $services->set(self::GUZZLE_CONSUMER, GuzzleConsumer::class)
            ->args([new Reference('test.guzzle.inlined')])
            ->public()
        ;
        // Unused services are removed on compile; the tests fetch these.
        $services->alias('test.http_client', 'http_client')->public();
        $services->alias('test.logger', 'logger')->public();
        $services->alias('test.bus.commands', 'bus.commands')->public();
        $services->alias('test.bus.events', 'bus.events')->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('ping', '/ping')->controller('kernel::ping');
    }
}
