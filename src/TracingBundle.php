<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle;

use Msstc4Symfony\TracingBundle\DependencyInjection\CompilerPass\BaggageRecipientsPass;
use Msstc4Symfony\TracingBundle\DependencyInjection\CompilerPass\GuzzlePass;
use Msstc4Symfony\TracingBundle\DependencyInjection\CompilerPass\HttpClientPass;
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

        $container->addCompilerPass(new BaggageRecipientsPass());
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
                    ->info('First half of the caller name this service sends downstream in W3C baggage.')
                    ->defaultValue('%env(default:msstc4symfony_tracing.unknown:APPLICATION_NAME)%')
                    ->cannotBeEmpty()
                ->end()
                ->stringNode('component_name')
                    ->info('Second half of the caller name this service sends downstream in W3C baggage.')
                    ->defaultValue('%env(default:msstc4symfony_tracing.unknown:COMPONENT_NAME)%')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('baggage')
                    ->addDefaultsIfNotSet()
                    ->fixXmlConfig('allowed_host')
                    ->children()
                        ->arrayNode('allowed_hosts')
                            ->info('Hosts outgoing HTTP requests send W3C baggage to: "orders", "10.0.0.1", "[::1]" or "*.example.com" for its subdomains. Not set: every host; empty: none. traceparent and tracestate go to every host.')
                            ->example(['orders', '*.internal.example.com'])
                            ->defaultNull()
                            // A later file (when@prod) replaces the list rather than adding to it.
                            ->performNoDeepMerging()
                            ->stringPrototype()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('sampling')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->floatNode('ratio')
                            ->info('Share of new traces marked sampled (0..1); an incoming traceparent keeps its own flag.')
                            // FloatNode keeps an int as is: "ratio: 0" would reach the container as int.
                            ->beforeNormalization()->always(static fn (mixed $ratio): mixed => is_int($ratio) ? (float) $ratio : $ratio)->end()
                            ->min(0.0)
                            ->max(1.0)
                            ->defaultValue(1.0)
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

        $container->parameters()
            ->set('msstc4symfony_tracing.application_name', $config['application_name'])
            ->set('msstc4symfony_tracing.component_name', $config['component_name'])
            ->set(BaggageRecipientsPass::PARAMETER, is_array($config['baggage']) ? $config['baggage']['allowed_hosts'] : null)
            ->set('msstc4symfony_tracing.sampling.ratio', is_array($config['sampling']) ? $config['sampling']['ratio'] : null)
        ;
    }
}
