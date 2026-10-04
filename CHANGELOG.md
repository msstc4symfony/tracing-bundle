# Changelog

All notable changes to this bundle are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

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

[1.0.0]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v1.0.0
