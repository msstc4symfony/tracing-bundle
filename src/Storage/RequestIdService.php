<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Storage;

use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final class RequestIdService implements RequestIdServiceInterface, W3cTraceContextInterface
{
    private string $runtimeId;

    private ?string $requestId = null;

    private ?string $requestFrom = null;

    private ?TraceParent $traceParent = null;

    private ?TraceParent $remoteTraceParent = null;

    private ?TraceState $traceState = null;

    public function __construct(
        #[Autowire(param: 'msstc4symfony_tracing.application_name')]
        private readonly string $applicationName,
        #[Autowire(param: 'msstc4symfony_tracing.component_name')]
        private readonly string $componentName,
    ) {
        $this->initRuntimeId();
    }

    #[Override]
    public function initRuntimeId(): static
    {
        $this->runtimeId = Uuid::v4()->toRfc4122();

        return $this;
    }

    #[Override]
    public function getRuntimeId(): string
    {
        return $this->runtimeId;
    }

    #[Override]
    public function resetRequestData(): void
    {
        $this->requestId = null;
        $this->requestFrom = null;
        $this->traceParent = null;
        $this->remoteTraceParent = null;
        $this->traceState = null;
    }

    #[Override]
    public function setRequestId(string $requestId): static
    {
        $this->requestId = $requestId;

        return $this;
    }

    #[Override]
    public function getRequestId(): string
    {
        return $this->ensureContext()[0];
    }

    #[Override]
    public function setRequestFrom(string $requestFrom): static
    {
        $this->requestFrom = $requestFrom;

        return $this;
    }

    #[Override]
    public function getRequestFrom(): string
    {
        return $this->ensureContext()[1];
    }

    #[Override]
    public function getCurrentRequestFrom(): string
    {
        return $this->applicationName . ':' . $this->componentName;
    }

    #[Override]
    public function generateRequestId(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    #[Override]
    public function generate(): static
    {
        $this->requestId = $this->generateRequestId();
        $this->requestFrom = $this->getCurrentRequestFrom();

        return $this;
    }

    #[Override]
    public function snapshot(): TraceContext
    {
        return new TraceContext(
            $this->runtimeId,
            $this->requestId,
            $this->requestFrom,
            $this->traceParent,
            $this->remoteTraceParent,
            $this->traceState,
        );
    }

    #[Override]
    public function restore(TraceContext $context): void
    {
        $this->runtimeId = $context->runtimeId;
        $this->requestId = $context->requestId;
        $this->requestFrom = $context->requestFrom;
        $this->traceParent = $context->traceParent;
        $this->remoteTraceParent = $context->remoteTraceParent;
        $this->traceState = $context->traceState;
    }

    #[Override]
    public function continueTrace(TraceParent $received, ?TraceState $state = null): static
    {
        $this->remoteTraceParent = $received;
        $this->traceParent = $received->child();
        $this->traceState = $state;

        return $this;
    }

    #[Override]
    public function getTraceParent(): TraceParent
    {
        return $this->traceParent ??= TraceParent::start();
    }

    #[Override]
    public function getRemoteTraceParent(): ?TraceParent
    {
        return $this->remoteTraceParent;
    }

    #[Override]
    public function getTraceState(): ?TraceState
    {
        return $this->traceState;
    }

    #[Override]
    public function createOutgoingTraceParent(): TraceParent
    {
        return $this->getTraceParent()->child();
    }

    /**
     * @return array{string, string} request id and request-from, generated when missing
     */
    private function ensureContext(): array
    {
        $this->requestId ??= $this->generateRequestId();
        $this->requestFrom ??= $this->getCurrentRequestFrom();

        return [$this->requestId, $this->requestFrom];
    }

    #[Override]
    public function reset(): void
    {
        $this->initRuntimeId();
        $this->resetRequestData();
    }
}
