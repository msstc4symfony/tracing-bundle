<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle;

use Msstc4Symfony\TracingBundle\DependencyInjection\Compiler\GuzzlePass;
use Msstc4Symfony\TracingBundle\DependencyInjection\Compiler\HttpClientPass;
use Msstc4Symfony\TracingBundle\DependencyInjection\Compiler\W3cTraceContextPass;
use Msstc4Symfony\TracingBundle\DependencyInjection\W3cTraceContextWiring;
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
        // Before autowiring, which resolves the alias into the integrations' arguments.
        $container->addCompilerPass(new W3cTraceContextPass());
        // Child definitions (parent: ...) have their class resolved only by optimization passes.
        $container->addCompilerPass(new GuzzlePass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    #[Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('w3c_trace_context')
                    ->info('W3C Trace Context (traceparent / tracestate) next to request-id, for OpenTelemetry interop. Adds headers and log extras only.')
                    ->canBeDisabled()
                    ->children()
                        ->booleanNode('messenger')
                            ->info('Also stamp dispatched messages. Enable once every consumer runs tracing-bundle >= 1.1: older ones cannot decode the stamp.')
                            ->defaultFalse()
                        ->end()
                    ->end()
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

        $w3c = $config['w3c_trace_context'] ?? null;
        if (is_array($w3c) && ($w3c['enabled'] ?? false) === true) {
            W3cTraceContextWiring::enable($builder, ($w3c['messenger'] ?? false) === true);
        }
    }
}
