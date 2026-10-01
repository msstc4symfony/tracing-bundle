<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration\Kernel;

use GuzzleHttp\Client;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\IncomingStampMiddleware;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\OutgoingStampMiddleware;
use Msstc4Symfony\TracingBundle\TracingBundle;
use Override;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\MessageBusInterface;
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
        return [
            new FrameworkBundle(),
            ...(self::hasMonologBundle() ? [new MonologBundle()] : []),
            new TracingBundle(),
        ];
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

    // Optional packages are wired only when installed, so the bundle is also booted the way an
    // application without them boots it.
    public static function hasMonologBundle(): bool
    {
        return class_exists(MonologBundle::class);
    }

    public static function hasHttpClient(): bool
    {
        return class_exists(HttpClient::class);
    }

    public static function hasMessenger(): bool
    {
        return interface_exists(MessageBusInterface::class);
    }

    public static function hasGuzzle(): bool
    {
        return class_exists(Client::class);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $framework = [
            'secret' => 'test',
            'http_method_override' => false,
            // Symfony 6.4 deprecates leaving it unset.
            'handle_all_throwables' => true,
            'test' => true,
            'router' => ['utf8' => true],
            // The php_errors logger installs a global handler that outlives the kernel and trips failOnRisky.
            'php_errors' => ['log' => false],
        ];
        $services = $container->services();

        if (self::hasHttpClient()) {
            $framework['http_client'] = ['mock_response_factory' => RecordingResponseFactory::class];
            $services->set(RecordingResponseFactory::class)->public();
            // Unused services are removed on compile; the tests fetch these.
            $services->alias('test.http_client', 'http_client')->public();
        }

        if (self::hasMessenger()) {
            $framework['messenger'] = [
                'default_bus' => 'bus.commands',
                'buses' => [
                    'bus.commands' => ['middleware' => [OutgoingStampMiddleware::class, IncomingStampMiddleware::class]],
                    'bus.events' => ['middleware' => [OutgoingStampMiddleware::class, IncomingStampMiddleware::class]],
                ],
            ];
            $services->alias('test.bus.commands', 'bus.commands')->public();
            $services->alias('test.bus.events', 'bus.events')->public();
        }

        $container->extension('framework', $framework);

        if (self::hasMonologBundle()) {
            $container->extension('monolog', [
                'handlers' => ['main' => ['type' => 'test']],
            ]);
            $services->alias('test.logger', 'logger')->public();
        } else {
            // FrameworkBundle's fallback logger writes debug records to stderr.
            $services->set('logger', NullLogger::class);
        }

        if (self::hasGuzzle()) {
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
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('ping', '/ping')->controller('kernel::ping');
    }
}
