<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\DependencyInjection\Compiler;

use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\W3cTraceContextInterface;
use Override;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Entry points reset and snapshot the RequestIdServiceInterface service; W3C state must live on
 * that same object. An application storage implementing both interfaces therefore takes over
 * the W3C alias too.
 */
final class W3cTraceContextPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(W3cTraceContextInterface::class) || !$container->has(RequestIdServiceInterface::class)) {
            return;
        }

        $reflection = $container->getReflectionClass($container->findDefinition(RequestIdServiceInterface::class)->getClass(), false);
        if ($reflection instanceof ReflectionClass && $reflection->implementsInterface(W3cTraceContextInterface::class)) {
            $container->setAlias(W3cTraceContextInterface::class, RequestIdServiceInterface::class)->setPublic(true);
        }
    }
}
