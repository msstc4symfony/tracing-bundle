<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle;

use Msstc4Symfony\TracingBundle\DependencyInjection\Compiler\GuzzlePass;
use Msstc4Symfony\TracingBundle\DependencyInjection\Compiler\HttpClientPass;
use Override;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class TracingBundle extends AbstractBundle
{
    protected string $extensionAlias = 'msstc4symfony_tracing';

    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new HttpClientPass());
        // Child definitions (parent: ...) have their class resolved only by optimization passes.
        $container->addCompilerPass(new GuzzlePass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    #[Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->stringNode('application_name')
                    ->info('First half of the request-from value this service sends downstream.')
                    ->defaultValue('%env(default:msstc4symfony_tracing.unknown:APPLICATION_NAME)%')
                    ->cannotBeEmpty()
                ->end()
                ->stringNode('component_name')
                    ->info('Second half of the request-from value this service sends downstream.')
                    ->defaultValue('%env(default:msstc4symfony_tracing.unknown:COMPONENT_NAME)%')
                    ->cannotBeEmpty()
                ->end()
            ->end()
        ;
    }

    /**
     * @param array<array-key, mixed> $config processed by configure()
     */
    #[Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/Resources/config/services.php');

        $container->parameters()
            ->set('msstc4symfony_tracing.application_name', $config['application_name'])
            ->set('msstc4symfony_tracing.component_name', $config['component_name'])
        ;
    }
}
