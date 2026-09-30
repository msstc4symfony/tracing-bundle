<?php

declare(strict_types=1);

namespace Hot\TracingBundle\Storage;

interface RequestIdServiceInterface
{
    public const UNKNOWN_APPLICATION = 'unknown';

    public function initRuntimeId(): static;

    public function getRuntimeId(): string;

    public function resetRequestData(): void;

    public function setRequestId(string $requestId): static;

    public function getRequestId(): string;

    public function setRequestFrom(string $requestFrom): static;

    public function getRequestFrom(): string;

    public function getCurrentRequestFrom(): string;

    public function flush(): static;

    public function generateRequestId(): string;

    public function generate(): static;
}
