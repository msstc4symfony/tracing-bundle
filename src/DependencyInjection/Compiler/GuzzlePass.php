<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\DependencyInjection\Compiler;

use GuzzleHttp\ClientInterface;
use Msstc4Symfony\TracingBundle\GuzzleHttp\RequestIdGuzzleHandler;
use Override;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class GuzzlePass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(ClientInterface::class) || !$container->hasDefinition(RequestIdGuzzleHandler::class)) {
            return;
        }

        foreach ($container->getDefinitions() as $definition) {
            // An existing configurator is the application's; do not replace it.
            if ($definition->isAbstract() || $definition->getConfigurator() !== null) {
                continue;
            }

            // getReflectionClass() resolves parameters and survives classes whose parent comes
            // from a missing package; is_a() would autoload them into a fatal error.
            $reflection = $container->getReflectionClass($definition->getClass(), false);
            if (!$reflection instanceof ReflectionClass || !$reflection->implementsInterface(ClientInterface::class)) {
                continue;
            }

            $definition->setConfigurator([new Reference(RequestIdGuzzleHandler::class), 'addHandler']);
        }
    }
}
