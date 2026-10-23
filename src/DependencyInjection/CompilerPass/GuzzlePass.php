<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\DependencyInjection\CompilerPass;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use Msstc4Symfony\TracingBundle\Infrastructure\Guzzle\ChainedClientConfigurator;
use Msstc4Symfony\TracingBundle\Infrastructure\Guzzle\TraceGuzzleMiddleware;
use Override;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Traces every Guzzle client service. A client constructed by `GuzzleHttp\Client::__construct()`
 * gets the middleware in its `handler` config, which works on Guzzle 7 and 8 and leaves any
 * configurator of the application alone. Other clients (built by a factory, with their own
 * constructor, with a config resolved only at run time or with options of the default handler
 * and no `handler`) are configured after creation through `getConfig()`; clients without it
 * cannot be reached and are only reported in the container compilation log.
 *
 * @internal
 */
final class GuzzlePass implements CompilerPassInterface
{
    private const UNREACHABLE_CLIENT_LOG = 'Guzzle client "%s" is not traced: its handler cannot be set from an array config of %s::__construct() and it has no getConfig(); '
        . 'create it with the handler returned by %s::decorateHandler().';

    // Options of the default handler, which Guzzle 8 Client::__construct() rejects or drops next to a "handler".
    private const DEFAULT_HANDLER_OPTIONS = ['max_host_connections', 'max_total_connections', 'transport_sharing', 'multiplex'];

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(ClientInterface::class) || !$container->hasDefinition(TraceGuzzleMiddleware::class)) {
            return;
        }

        $tracing = new Reference(TraceGuzzleMiddleware::class);

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract()) {
                continue;
            }

            // getReflectionClass() resolves parameters and survives classes whose parent comes
            // from a missing package; is_a() would autoload them into a fatal error.
            $reflection = $container->getReflectionClass($definition->getClass(), false);
            if (!$reflection instanceof ReflectionClass || !$reflection->implementsInterface(ClientInterface::class)) {
                continue;
            }

            if ($this->traceThroughConfig($definition, $reflection, $tracing)) {
                continue;
            }

            if (!$reflection->hasMethod('getConfig')) {
                $container->log($this, sprintf(self::UNREACHABLE_CLIENT_LOG, $id, Client::class, TraceGuzzleMiddleware::class));

                continue;
            }

            $configurator = $definition->getConfigurator();
            $definition->setConfigurator($configurator === null
                ? [$tracing, 'addHandler']
                : [new Definition(ChainedClientConfigurator::class, [$configurator, $tracing]), '__invoke']);
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function traceThroughConfig(Definition $definition, ReflectionClass $reflection, Reference $tracing): bool
    {
        if ($definition->getFactory() !== null || $reflection->getConstructor()?->getDeclaringClass()->getName() !== Client::class) {
            return false;
        }

        // Runs after ResolveNamedArgumentsPass: a `$config` argument is already at index 0.
        $config = $definition->getArguments()[0] ?? [];

        // A parameter or expression is resolved only later; its content cannot be extended here.
        if (!is_array($config)) {
            return false;
        }

        $handler = $config['handler'] ?? null;
        if ($handler === null && array_intersect(self::DEFAULT_HANDLER_OPTIONS, array_keys($config)) !== []) {
            return false;
        }

        $config['handler'] = (new Definition(HandlerStack::class, [$handler]))
            ->setFactory([$tracing, 'decorateHandler'])
        ;
        $definition->setArgument(0, $config);

        return true;
    }
}
