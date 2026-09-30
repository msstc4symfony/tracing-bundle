<?php

declare(strict_types=1);

namespace Hot\TracingBundle\Storage;

use Symfony\Component\Uid\Uuid;

final class RequestIdService implements RequestIdServiceInterface
{
    private string $runtimeId;

    private ?string $requestId = null;

    private ?string $requestFrom = null;

    public function __construct(
        private readonly string $applicationName,
        private readonly string $componentName,
    ) {
        $this->initRuntimeId();
    }

    public function initRuntimeId(): static
    {
        $this->runtimeId = Uuid::v4()->toRfc4122();

        return $this;
    }

    public function getRuntimeId(): string
    {
        return $this->runtimeId;
    }

    public function resetRequestData(): void
    {
        $this->requestId = null;
        $this->requestFrom = null;
    }

    public function setRequestId(string $requestId): static
    {
        $this->requestId = $requestId;

        return $this;
    }

    public function getRequestId(): string
    {
        return $this->requestId ?? $this->generate()->requestId;
    }

    public function setRequestFrom(string $requestFrom): static
    {
        $this->requestFrom = $requestFrom;

        return $this;
    }

    public function getRequestFrom(): string
    {
        return $this->requestFrom ?? $this->generate()->requestFrom;
    }

    public function getCurrentRequestFrom(): string
    {
        return $this->applicationName . ':' . $this->componentName;
    }

    public function flush(): static
    {
        $this->requestId = null;
        $this->requestFrom = null;

        return $this;
    }

    public function generateRequestId(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    public function generate(): static
    {
        $this->requestId = $this->generateRequestId();
        $this->requestFrom = $this->getCurrentRequestFrom();

        return $this;
    }
}
