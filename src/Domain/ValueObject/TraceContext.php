<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Domain\ValueObject;

/**
 * Trace of the current unit of work: an HTTP request, a console command or a consumed message.
 * Every field is set on creation, so a nested unit that restores this context loses nothing.
 */
final readonly class TraceContext
{
    public const CALLER_KEY = 'msstc4symfony.caller';

    /**
     * @param non-empty-string $runtimeId UUID v4 of the unit of work; kept by child spans
     * @param TraceParent $traceParent this unit's span
     * @param non-empty-string|null $parentSpanId span id of the unit that started this one; null at the root
     */
    private function __construct(
        public string $runtimeId,
        public TraceParent $traceParent,
        public ?TraceParent $remoteTraceParent,
        public ?TraceState $traceState,
        public Baggage $baggage,
        public ?string $parentSpanId,
    ) {
    }

    public static function start(?SampleRatio $ratio = null): self
    {
        $traceParent = TraceParent::start($ratio);

        return new self(RuntimeId::generate(), $traceParent, null, self::sampledState($ratio, $traceParent), Baggage::empty(), null);
    }

    /**
     * Joins the caller's trace in this unit of work: logs written before the trace arrived keep their runtime id.
     */
    public function continuing(TraceParent $received, ?TraceState $state, Baggage $baggage): self
    {
        return new self($this->runtimeId, $received->child(), $received, $state, $baggage, $received->spanId);
    }

    public function withBaggage(Baggage $baggage): self
    {
        return new self($this->runtimeId, $this->traceParent, $this->remoteTraceParent, $this->traceState, $baggage, $this->parentSpanId);
    }

    /**
     * A nested unit of work: same trace and runtime, a new span whose parent is this span.
     */
    public function withChildSpan(): self
    {
        return new self($this->runtimeId, $this->traceParent->child(), $this->remoteTraceParent, $this->traceState, $this->baggage, $this->traceParent->spanId);
    }

    public function caller(): ?string
    {
        return $this->baggage->get(self::CALLER_KEY);
    }

    /**
     * This span, not a child: the receiver logs it as parent_span_id, and this unit logs it as span_id.
     */
    public function outgoing(string $caller): OutgoingTrace
    {
        return new OutgoingTrace($this->traceParent, $this->traceState, $this->baggage->with(self::CALLER_KEY, $caller));
    }

    /**
     * A sampled trace tells the services after it the threshold it was sampled at, so they can sample the same trace
     * consistently; a ratio of 1 drops nothing and leaves tracestate out.
     */
    private static function sampledState(?SampleRatio $ratio, TraceParent $traceParent): ?TraceState
    {
        if (!$ratio instanceof SampleRatio || !$ratio->dropsAny() || !$traceParent->isSampled()) {
            return null;
        }

        $threshold = $ratio->threshold();

        return $threshold === null ? null : TraceState::sampledAt($threshold);
    }
}
