<?php

declare(strict_types=1);

namespace Hot\TracingBundle;

use Hot\TracingBundle\DependencyInjection\Compiler\GuzzlePass;
use Hot\TracingBundle\DependencyInjection\Compiler\HttpClientPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class TracingBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new HttpClientPass());
        $container->addCompilerPass(new GuzzlePass());
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('./Resources/config/services.yaml');
    }
}
