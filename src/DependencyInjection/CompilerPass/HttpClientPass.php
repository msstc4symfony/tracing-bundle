<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\DependencyInjection\CompilerPass;

use Msstc4Symfony\TracingBundle\Application\CallerName;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Infrastructure\HttpClient\TraceHttpClientDecorator;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\DecoratorTrait;

/**
 * Every FrameworkBundle client, default and scoped, ends in the shared transport, so
 * decorating that single point covers them all exactly once.
 *
 * @internal
 */
final class HttpClientPass implements CompilerPassInterface
{
    public const TRANSPORT_ID = 'http_client.transport';

    public const DECORATOR_ID = 'msstc4symfony_tracing.http_client.transport';

    // Registered in bundle-standard doc/decoration-priorities.md. A lower priority is applied later, i.e. further
    // out: outside FrameworkBundle's mock_response_factory decorator (-10), so functional tests with a mocked
    // transport still get the headers.
    private const DECORATION_PRIORITY = -15;

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!trait_exists(DecoratorTrait::class) || !$container->hasDefinition(self::TRANSPORT_ID)) {
            return;
        }

        $transport = $container->getDefinition(self::TRANSPORT_ID);
        if ($transport->isAbstract()) {
            return;
        }

        $container->register(self::DECORATOR_ID, TraceHttpClientDecorator::class)
            ->setArgument('$inner', new Reference(self::DECORATOR_ID . '.inner'))
            ->setArgument('$holder', new Reference(TraceContextHolderInterface::class))
            ->setArgument('$caller', new Reference(CallerName::class))
            ->setDecoratedService(self::TRANSPORT_ID, null, self::DECORATION_PRIORITY)
        ;
    }
}
