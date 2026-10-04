<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration\Kernel;

use Psr\Log\LoggerInterface;

/**
 * Logs ahead of every other request listener, the tracing one included.
 */
final readonly class EarlyLogListener
{
    public const string MESSAGE = 'before the tracing listener';

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $this->logger->info(self::MESSAGE);
    }
}
