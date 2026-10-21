<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\DependencyInjection\CompilerPass;

use InvalidArgumentException;
use Msstc4Symfony\TracingBundle\Application\BaggageRecipients;
use Override;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Rejects an invalid `baggage.allowed_hosts` entry when the container is built, not on the first outgoing request.
 *
 * @internal
 */
final class BaggageRecipientsPass implements CompilerPassInterface
{
    public const PARAMETER = 'msstc4symfony_tracing.baggage.allowed_hosts';

    /**
     * @throws InvalidConfigurationException when an entry is not a host, an IP address or a `*.` wildcard
     */
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER)) {
            return;
        }

        $hosts = $container->getParameterBag()->resolveValue($container->getParameter(self::PARAMETER));
        if (!is_array($hosts)) {
            return;
        }

        $literal = [];
        foreach ($hosts as $host) {
            if (!is_string($host)) {
                throw $this->invalid(sprintf('%s is not a string.', var_export($host, true)));
            }

            // An entry built from an %env()% is known only at runtime; BaggageRecipients checks it when it is built.
            $envs = [];
            $container->resolveEnvPlaceholders($host, null, $envs);
            if ($envs === []) {
                $literal[] = $host;
            }
        }

        try {
            new BaggageRecipients($literal);
        } catch (InvalidArgumentException $invalid) {
            throw $this->invalid($invalid->getMessage(), $invalid);
        }
    }

    private function invalid(string $reason, ?InvalidArgumentException $previous = null): InvalidConfigurationException
    {
        return new InvalidConfigurationException(sprintf('Invalid configuration for path "%s": %s', self::PARAMETER, $reason), previous: $previous);
    }
}
