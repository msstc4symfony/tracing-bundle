<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Storage;

use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;

/**
 * W3C Trace Context of the current unit of work. Kept on the same object as the request id, so
 * reset(), snapshot() and restore() of RequestIdServiceInterface cover it too.
 *
 * Registered only while `w3c_trace_context` is enabled. Implement it only next to
 * RequestIdServiceInterface on the same class; W3cTraceContextPass then aliases both to it.
 */
interface W3cTraceContextInterface
{
    /**
     * Joins the caller's trace; this unit gets its own span id.
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
     * This unit's trace and flags with a new span id: one per outgoing request or message.
     */
    public function createOutgoingTraceParent(): TraceParent;
}
