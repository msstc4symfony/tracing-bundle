<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\DependencyInjection\Compiler;

use GuzzleHttp\ClientInterface;
use Msstc4Symfony\TracingBundle\GuzzleHttp\RequestIdGuzzleHandler;
use Override;
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
            $class = $definition->getClass();

            if (
                $class === null
                || $definition->isAbstract()
                || !is_a($class, ClientInterface::class, true)
                // An existing configurator is the application's; do not replace it.
                || $definition->getConfigurator() !== null
            ) {
                continue;
            }

            $definition->setConfigurator([new Reference(RequestIdGuzzleHandler::class), 'addHandler']);
        }
    }
}
