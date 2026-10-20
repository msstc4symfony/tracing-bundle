<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Application;

use InvalidArgumentException;

/**
 * This service as named to the services it calls: "<application>:<component>".
 */
final readonly class CallerName
{
    /** @var non-empty-string */
    public string $value;

    /**
     * @throws InvalidArgumentException when either name is empty
     */
    public function __construct(string $applicationName, string $componentName)
    {
        if ($applicationName === '' || $componentName === '') {
            throw new InvalidArgumentException('Application and component names must not be empty.');
        }

        $this->value = $applicationName . ':' . $componentName;
    }
}
