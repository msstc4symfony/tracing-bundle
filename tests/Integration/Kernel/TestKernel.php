<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration\Kernel;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Msstc4Symfony\TracingBundle\Framework\Messenger\IncomingTraceMiddleware;
use Msstc4Symfony\TracingBundle\Framework\Messenger\OutgoingTraceMiddleware;
use Msstc4Symfony\TracingBundle\Framework\Sentry\TracingIntegration;
use Msstc4Symfony\TracingBundle\TracingBundle;
use Override;
use Psr\Log\NullLogger;
use Sentry\Integration\IntegrationInterface;
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

    public const GUZZLE_CLIENT = 'test.guzzle';

    public const GUZZLE_CHILD_CLIENT = 'test.guzzle.child';

    public const GUZZLE_CONSUMER = 'test.guzzle.consumer';

    public const GUZZLE_CONFIGURED_CLIENT = 'test.guzzle.configured';

    public const GUZZLE_STACK_CLIENT = 'test.guzzle.with_stack';

    public const GUZZLE_FACTORY_CLIENT = 'test.guzzle.factory';

    public const GUZZLE_FACTORY_CONFIGURED_CLIENT = 'test.guzzle.factory_configured';

    public const GUZZLE_POOLED_CLIENT = 'test.guzzle.pooled';

    public const GUZZLE_POOLED_CONFIGURED_CLIENT = 'test.guzzle.pooled_configured';

    public const GUZZLE_NON_MULTIPLEXING_CLIENT = 'test.guzzle.non_multiplexing';

    public const SENTRY_INTEGRATION = 'test.sentry.integration';

    public const ENV_NAMED = 'named';

    public const ENV_LEGACY_W3C = 'legacy_w3c';

    public const ENV_BOOL_NAME = 'bool_name';

    public const ENV_INT_NAME = 'int_name';

    public const ENV_BAGGAGE_TO_EXAMPLE = 'baggage_to_example';

    public const ENV_BAGGAGE_TO_ORDERS = 'baggage_to_orders';

    public const ENV_BAGGAGE_TO_INVALID = 'baggage_to_invalid';

    public const ENV_UNSAMPLED = 'unsampled';

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

    public static function hasSentry(): bool
    {
        return interface_exists(IntegrationInterface::class);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $framework = [
            'secret' => 'test',
            'http_method_override' => false,
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
            $services->set(OutgoingCallController::class)->autowire()->tag('controller.service_arguments')->public();
        }

        if (self::hasMessenger()) {
            $framework['messenger'] = [
                'default_bus' => 'bus.commands',
                'buses' => [
                    'bus.commands' => ['middleware' => [OutgoingTraceMiddleware::class, IncomingTraceMiddleware::class]],
                    'bus.events' => ['middleware' => [OutgoingTraceMiddleware::class, IncomingTraceMiddleware::class]],
                ],
                // Serialized, so the stamps go through the default serializer as on a real transport.
                'transports' => ['async' => 'in-memory://?serialize=true'],
                'routing' => [TracedMessage::class => 'async'],
            ];
            $services->alias('test.bus.commands', 'bus.commands')->public();
            $services->alias('test.bus.events', 'bus.events')->public();
            $services->alias('test.transport.async', 'messenger.transport.async')->public();
            $services->set(TracedMessageHandler::class)
                ->autowire()
                ->tag('messenger.message_handler', ['bus' => 'bus.commands', 'handles' => TracedMessage::class])
                ->public()
            ;
        }

        $container->extension('framework', $framework);

        $tracing = match ($this->environment) {
            self::ENV_NAMED => ['application_name' => 'shop', 'component_name' => 'api'],
            self::ENV_LEGACY_W3C => ['w3c_trace_context' => false],
            self::ENV_BOOL_NAME => ['application_name' => true],
            self::ENV_INT_NAME => ['component_name' => 42],
            self::ENV_BAGGAGE_TO_EXAMPLE => ['baggage' => ['allowed_hosts' => ['orders', 'Example.com']]],
            self::ENV_BAGGAGE_TO_ORDERS => ['baggage' => ['allowed_hosts' => ['orders', '*.example.com']]],
            self::ENV_BAGGAGE_TO_INVALID => ['baggage' => ['allowed_hosts' => ['orders:8080']]],
            self::ENV_UNSAMPLED => ['sampling' => ['ratio' => 0.0]],
            default => null,
        };
        if ($tracing !== null) {
            $container->extension('msstc4symfony_tracing', $tracing);
        }

        if (self::hasMonologBundle()) {
            $container->extension('monolog', [
                'handlers' => ['main' => ['type' => 'test']],
            ]);
            $services->alias('test.logger', 'logger')->public();
            $services->set(EarlyLogListener::class)
                ->args([new Reference('logger')])
                ->tag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => 100_000])
            ;
        } else {
            // FrameworkBundle's fallback logger writes debug records to stderr.
            $services->set('logger', NullLogger::class);
        }

        if (self::hasSentry()) {
            $services->alias(self::SENTRY_INTEGRATION, TracingIntegration::class)->public();
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
            $services->set(AppGuzzleConfigurator::class)->public();
            $services->set(self::GUZZLE_CONFIGURED_CLIENT, Client::class)
                ->configurator([new Reference(AppGuzzleConfigurator::class), 'configure'])
                ->public()
            ;
            $services->set('test.guzzle.stack', HandlerStack::class)->factory(HandlerStack::create(...));
            $services->alias('test.guzzle.stack.public', 'test.guzzle.stack')->public();
            $services->set(self::GUZZLE_STACK_CLIENT, Client::class)
                ->args([['handler' => new Reference('test.guzzle.stack'), 'timeout' => 3]])
                ->public()
            ;
            $services->set(self::GUZZLE_FACTORY_CLIENT, Client::class)
                ->factory(GuzzleClientFactory::create(...))
                ->public()
            ;
            $services->set(self::GUZZLE_FACTORY_CONFIGURED_CLIENT, Client::class)
                ->factory(GuzzleClientFactory::create(...))
                ->configurator([new Reference(AppGuzzleConfigurator::class), 'configure'])
                ->public()
            ;
            // Guzzle 8 rejects or drops these options next to a "handler".
            $services->set(self::GUZZLE_POOLED_CLIENT, Client::class)
                ->args([['max_host_connections' => 4, 'max_total_connections' => 16]])
                ->public()
            ;
            $services->set(self::GUZZLE_POOLED_CONFIGURED_CLIENT, Client::class)
                ->args([['max_host_connections' => 4]])
                ->configurator([new Reference(AppGuzzleConfigurator::class), 'configure'])
                ->public()
            ;
            $services->set(self::GUZZLE_NON_MULTIPLEXING_CLIENT, Client::class)
                ->args([['multiplex' => 'none']])
                ->public()
            ;
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('ping', '/ping')->controller('kernel::ping');

        if (self::hasHttpClient()) {
            $routes->add('call', '/call')->controller(OutgoingCallController::class);
        }
    }
}
