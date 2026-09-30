<?php

declare(strict_types=1);

namespace Hot\TracingBundle\DependencyInjection\Compiler;

use GuzzleHttp\ClientInterface;
use Hot\TracingBundle\GuzzleHttp\RequestIdGuzzleDecorator;
use Hot\TracingBundle\GuzzleHttp\RequestIdGuzzleHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class GuzzlePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!($definition->getClass() instanceof ClientInterface)) {
                continue;
            }

            $decoratorId = 'zumlin.tracing.guzzle.decorator.' . $id;
            $definition = new Definition(RequestIdGuzzleDecorator::class)
                ->setArgument('$client', new Reference($id))
                ->setArgument('$handler', new Reference(RequestIdGuzzleHandler::class))
                ->setDecoratedService($id)
            ;
            $container->setDefinition($decoratorId, $definition);
        }
    }
}
