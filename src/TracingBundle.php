<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle;

use Msstc4Symfony\TracingBundle\DependencyInjection\Compiler\GuzzlePass;
use Msstc4Symfony\TracingBundle\DependencyInjection\Compiler\HttpClientPass;
use Override;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class TracingBundle extends AbstractBundle
{
    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new HttpClientPass());
        // Child definitions (parent: ...) have their class resolved only by optimization passes.
        $container->addCompilerPass(new GuzzlePass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/Resources/config/services.php');
    }
}
