<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Storage;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Trace context of the current unit of work: an HTTP request, a console command or a
 * consumed message. Reset between units so long-running workers never leak ids.
 */
interface RequestIdServiceInterface extends ResetInterface
{
    public const string UNKNOWN_APPLICATION = 'unknown';

    public function initRuntimeId(): static;

    public function getRuntimeId(): string;

    public function resetRequestData(): void;

    public function setRequestId(string $requestId): static;

    /**
     * Generates a fresh id on first access when none was received.
     */
    public function getRequestId(): string;

    public function setRequestFrom(string $requestFrom): static;

    public function getRequestFrom(): string;

    /**
     * "application:component" of this service, sent as request-from downstream.
     */
    public function getCurrentRequestFrom(): string;

    public function generateRequestId(): string;

    public function generate(): static;
}
