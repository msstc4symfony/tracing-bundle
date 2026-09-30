<?php

declare(strict_types=1);

namespace Hot\TracingBundle\DependencyInjection\Compiler;

use Hot\TracingBundle\HttpClient\HttpClientDecorator;
use Hot\TracingBundle\Storage\RequestIdServiceInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HttpClientPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!($definition->getClass() instanceof HttpClientInterface)) {
                continue;
            }

            $decoratorId = 'zumlin.tracing.http_client.decorator.' . $id;
            $definition = new Definition(HttpClientDecorator::class)
                ->setArgument('$inner', new Reference($id))
                ->setArgument('$requestIdService', new Reference(RequestIdServiceInterface::class))
                ->setDecoratedService($id)
            ;
            $container->setDefinition($decoratorId, $definition);
        }
    }
}
