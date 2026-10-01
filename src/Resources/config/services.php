<?php

declare(strict_types=1);

use GuzzleHttp\ClientInterface;
use Msstc4Symfony\TracingBundle\EventListener\ConsoleSubscriber;
use Msstc4Symfony\TracingBundle\EventListener\HTTPRequestListener;
use Msstc4Symfony\TracingBundle\GuzzleHttp\RequestIdGuzzleHandler;
use Msstc4Symfony\TracingBundle\Messenger\EventListener\WorkerTraceSubscriber;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\IncomingStampMiddleware;
use Msstc4Symfony\TracingBundle\Messenger\Middleware\OutgoingStampMiddleware;
use Msstc4Symfony\TracingBundle\Monolog\RequestIdProcessor;
use Msstc4Symfony\TracingBundle\Sentry\Integration\TracingIntegration;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Sentry\Integration\IntegrationInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        ->set('msstc4symfony_tracing.application_name', '%env(default:msstc4symfony_tracing.unknown:APPLICATION_NAME)%')
        ->set('msstc4symfony_tracing.component_name', '%env(default:msstc4symfony_tracing.unknown:COMPONENT_NAME)%')
        ->set('msstc4symfony_tracing.unknown', RequestIdServiceInterface::UNKNOWN_APPLICATION)
    ;

    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    $services->set(RequestIdService::class)
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->alias(RequestIdServiceInterface::class, RequestIdService::class)->public();

    $services->set(HTTPRequestListener::class);
    $services->set(ConsoleSubscriber::class);
    $services->set(RequestIdProcessor::class)->tag('monolog.processor');

    // Optional integrations are registered only when their library is installed.
    if (interface_exists(ClientInterface::class)) {
        $services->set(RequestIdGuzzleHandler::class);
    }

    if (interface_exists(MiddlewareInterface::class)) {
        $services->set(IncomingStampMiddleware::class);
        $services->set(OutgoingStampMiddleware::class);
        $services->set(WorkerTraceSubscriber::class);
    }

    if (interface_exists(IntegrationInterface::class)) {
        $services->set(TracingIntegration::class);
    }
};
