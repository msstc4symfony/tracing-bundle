# Changelog

All notable changes to this bundle are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

## [1.3.0] - 2026-10-14

### Changed

- Requires PHP 8.2 instead of 8.4 (Symfony 8 still needs PHP 8.4).
- Class constants no longer declare a native type (PHP 8.2 has none); values are unchanged.

## [1.2.0] - 2026-10-13

### Added

- `TraceContext::withChildSpan()`: a copy for a unit nested in this one — same trace, request and
  remote context, a new span id, this span as `parentSpanId`. Throws `LogicException` when the
  context has no `traceParent`.

## [1.1.0] - 2026-10-13

### Added

- `parent_span_id` in every Monolog record's `extra`: the span id of the unit that started this
  one (the received `traceparent`), `null` when this unit started the trace.
- `TraceContext::$parentSpanId` (optional last constructor argument), carried by `snapshot()` /
  `restore()` and cleared on reset.

### Changed

- Outgoing `traceparent` (HttpClient, Guzzle, `TraceStamp`) carries this unit's own span id
  instead of a new one per request or message, so a receiver's `parent_span_id` is a `span_id` in
  the sender's logs. Calls made by one unit now share their `parent-id`.

### Upgrading

- A custom `RequestIdServiceInterface` implementation must pass the parent span id to the
  `TraceContext` its `snapshot()` returns; otherwise `parent_span_id` is always `null`. It must be
  a W3C span id (16 lowercase hex digits, not all zero): `TraceContext` throws
  `InvalidArgumentException` otherwise, and `RequestIdProcessor` calls `snapshot()` for every record.

## [1.0.0] - 2026-10-04

First release of `msstc4symfony/tracing-bundle` (namespace `Msstc4Symfony\TracingBundle`).

### Added

- Trace context of every unit of work: runtime id, request id, `request from`
  (`application:component`) and W3C Trace Context (`traceparent` / `tracestate`), held by
  `RequestIdServiceInterface` and reset on `kernel.reset`.
- HTTP: incoming `request-id` / `request-from` / `traceparent` / `tracestate` headers are
  continued and returned on the response; logs written before the request listener keep their
  ids. Console commands start a new trace.
- Outgoing propagation: every framework HttpClient (decorated `http_client.transport`) and
  every Guzzle 7 / 8 client service (handler-stack middleware, own configurators kept); headers
  set by the caller are never overwritten.
- Messenger (opt-in middleware): one `TraceStamp(requestId, requestFrom, traceParent, ?traceState)`
  on dispatched messages; consumed messages run in the sender's trace, synchronous ones return
  to the caller's trace afterwards.
- Monolog processor adding `runtime_id`, `request_id`, `request_from`, `trace_id`, `span_id`
  to every record.
- Opt-in Sentry `TracingIntegration`: trace values in `extra`, `trace_id` / `span_id` tags.
- Configuration under the `msstc4symfony_tracing` root: `application_name`, `component_name`
  (defaults: the `APPLICATION_NAME` / `COMPONENT_NAME` environment variables, else `unknown`).

### Requirements

- PHP >= 8.4, Symfony ^7.4|^8.0, Monolog ^3.5, `symfony/service-contracts` ^3.
- Optional: Guzzle 7 / 8, Symfony HttpClient, Messenger, MonologBundle, `sentry/sentry` 4.x.

[1.3.0]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v1.3.0
[1.2.0]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v1.2.0
[1.1.0]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v1.0.0
