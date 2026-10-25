<?php

declare(strict_types=1);

use GuzzleHttp\ClientInterface;
use Msstc4Symfony\TracingBundle\Application\BaggageRecipients;
use Msstc4Symfony\TracingBundle\Application\CallerName;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\SampleRatio;
use Msstc4Symfony\TracingBundle\Framework\Messenger\IncomingTraceMiddleware;
use Msstc4Symfony\TracingBundle\Framework\Messenger\OutgoingTraceMiddleware;
use Msstc4Symfony\TracingBundle\Framework\Messenger\WorkerTraceSubscriber;
use Msstc4Symfony\TracingBundle\Framework\Monolog\TraceLogProcessor;
use Msstc4Symfony\TracingBundle\Framework\Sentry\TracingIntegration;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use Msstc4Symfony\TracingBundle\Infrastructure\Guzzle\TraceGuzzleMiddleware;
use Msstc4Symfony\TracingBundle\Presentation\EventListener\ConsoleTraceSubscriber;
use Msstc4Symfony\TracingBundle\Presentation\EventListener\HttpTraceListener;
use Sentry\Integration\IntegrationInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        ->set('msstc4symfony_tracing.unknown', 'unknown')
    ;

    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    $services->set('msstc4symfony_tracing.sample_ratio', SampleRatio::class)
        ->arg('$ratio', param('msstc4symfony_tracing.sampling.ratio'))
    ;
    $services->set(InMemoryTraceContextHolder::class)
        ->arg('$sampleRatio', service('msstc4symfony_tracing.sample_ratio'))
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->alias(TraceContextHolderInterface::class, InMemoryTraceContextHolder::class)->public();
    $services->set(BaggageRecipients::class)
        ->arg('$allowedHosts', param('msstc4symfony_tracing.baggage.allowed_hosts'))
    ;
    $services->set(CallerName::class)
        ->arg('$applicationName', param('msstc4symfony_tracing.application_name'))
        ->arg('$componentName', param('msstc4symfony_tracing.component_name'))
    ;

    $services->set(HttpTraceListener::class)
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->set(ConsoleTraceSubscriber::class);
    $services->set(TraceLogProcessor::class)->tag('monolog.processor');

    // Optional integrations are registered only when their library is installed.
    if (interface_exists(ClientInterface::class)) {
        $services->set(TraceGuzzleMiddleware::class);
    }

    if (interface_exists(MiddlewareInterface::class)) {
        $services->set(IncomingTraceMiddleware::class)
            ->arg('$sampleRatio', service('msstc4symfony_tracing.sample_ratio'))
        ;
        $services->set(OutgoingTraceMiddleware::class);
        $services->set(WorkerTraceSubscriber::class);
    }

    if (interface_exists(IntegrationInterface::class)) {
        $services->set(TracingIntegration::class);
    }
};
