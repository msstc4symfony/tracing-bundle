<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\DependencyInjection\Compiler;

use Msstc4Symfony\TracingBundle\HttpClient\HttpClientDecorator;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\W3cTraceContextInterface;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\DecoratorTrait;

/**
 * Every FrameworkBundle client, default and scoped, ends in the shared transport, so
 * decorating that single point covers them all exactly once.
 */
final class HttpClientPass implements CompilerPassInterface
{
    public const string TRANSPORT_ID = 'http_client.transport';

    public const string DECORATOR_ID = 'msstc4symfony_tracing.http_client.transport';

    // A lower priority is applied later, i.e. further out: outside FrameworkBundle's
    // mock_response_factory decorator (-10), so functional tests with a mocked transport
    // still get the headers.
    private const int DECORATION_PRIORITY = -15;

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

        $container->register(self::DECORATOR_ID, HttpClientDecorator::class)
            ->setArgument('$inner', new Reference(self::DECORATOR_ID . '.inner'))
            ->setArgument('$requestIdService', new Reference(RequestIdServiceInterface::class))
            // Registered only while w3c_trace_context is enabled.
            ->setArgument('$w3cTraceContext', new Reference(W3cTraceContextInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setDecoratedService(self::TRANSPORT_ID, null, self::DECORATION_PRIORITY)
        ;
    }
}
