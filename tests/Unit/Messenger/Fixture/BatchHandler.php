<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Messenger\Fixture;

use Closure;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;

/**
 * Own file: a test file declaring it would fatal on load when symfony/messenger is absent.
 */
final class BatchHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    /** @param Closure(string):void $onFlush */
    public function __construct(
        private readonly Closure $onFlush,
    ) {
    }

    public function __invoke(BatchedMessage $message, ?Acknowledger $ack = null): mixed
    {
        return $this->handle($message, $ack);
    }

    /**
     * @param list<array{object, Acknowledger}> $jobs
     */
    protected function process(array $jobs): void
    {
        foreach ($jobs as [, $ack]) {
            ($this->onFlush)('flushed');
            $ack->ack();
        }
    }

    protected function getBatchSize(): int
    {
        return 100;
    }
}
