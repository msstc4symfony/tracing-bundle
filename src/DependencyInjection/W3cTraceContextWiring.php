<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\DependencyInjection;

use Msstc4Symfony\TracingBundle\Messenger\Middleware\OutgoingStampMiddleware;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\W3cTraceContextInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Turns W3C Trace Context on. Integrations take the context as an optional argument, so
 * without the alias registered here autowiring passes null and they skip W3C.
 */
final class W3cTraceContextWiring
{
    public static function enable(ContainerBuilder $builder, bool $stampMessages): void
    {
        $builder->setAlias(W3cTraceContextInterface::class, RequestIdService::class)->setPublic(true);

        if (!$stampMessages && $builder->hasDefinition(OutgoingStampMiddleware::class)) {
            $builder->getDefinition(OutgoingStampMiddleware::class)->setArgument('$w3cTraceContext', null);
        }
    }
}
