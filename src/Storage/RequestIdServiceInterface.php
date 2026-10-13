<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Storage;

use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Trace context of the current unit of work: an HTTP request, a console command or a
 * consumed message. Reset between units so long-running workers never leak ids.
 *
 * Holds the request id and the W3C Trace Context together, so reset(), snapshot() and restore()
 * cover both.
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

    /**
     * Must carry the parent span id: `RequestIdProcessor` logs it as `parent_span_id`.
     */
    public function snapshot(): TraceContext;

    public function restore(TraceContext $context): void;

    /**
     * Joins the caller's trace; this unit gets its own span id and the caller's span as parent.
     */
    public function continueTrace(TraceParent $received, ?TraceState $state = null): static;

    /**
     * This unit's span: the received trace (or a new one, started on first access) with this
     * unit's own span id as parent id.
     */
    public function getTraceParent(): TraceParent;

    /**
     * The traceparent as received; null when this unit started the trace.
     */
    public function getRemoteTraceParent(): ?TraceParent;

    public function getTraceState(): ?TraceState;

    /**
     * This unit's span, sent as the parent of an outgoing request or message: the receiver's parent span id
     * is then a span id this unit logs.
     *
     * @return TraceParent the same span as getTraceParent(), not a new one
     */
    public function createOutgoingTraceParent(): TraceParent;
}
