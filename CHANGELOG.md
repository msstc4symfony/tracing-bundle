# Changelog

All notable changes to this bundle are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

## [2.3.0] - 2026-10-26

### Added

- New traces carry the W3C Level 2 random flag: `traceparent` flags `03` (sampled) or `02` (dropped by
  `sampling.ratio`) instead of `01` / `00`. Continued traces keep the received flags.
- A new trace sampled at a `sampling.ratio` below 1 sends `tracestate: ot=th:<threshold>` (OpenTelemetry consistent
  probability sampling, OTEP 235), so downstream OpenTelemetry samplers know the probability it was sampled at.
- `TraceParent::isSampled()`, `SampleRatio::threshold()`, `TraceState::sampledAt()`.

## [2.2.0] - 2026-10-25

### Added

- `sampling.ratio` (0..1, default 1.0): the share of new traces marked sampled. The decision depends on the trace id
  alone (W3C Trace Context Level 2 / OpenTelemetry `TraceIdRatioBased`: the 56 lowest bits against the rejection
  threshold), so services with one ratio decide alike; the older OpenTelemetry sampler that compares the lowest 64 bits
  may decide differently. A trace continued from an incoming `traceparent` keeps the
  caller's flag.
- `Domain\ValueObject\SampleRatio`; `TraceParent::start()` and `TraceContext::start()` take an optional ratio.

## [2.1.3] - 2026-10-23

### Fixed

- A Guzzle 8 client whose config sets `max_host_connections`, `max_total_connections` or a `*_require`
  `transport_sharing` and no `handler` can be created again, and one with `multiplex: none` or another
  `transport_sharing` mode keeps the default handler Guzzle builds from it: Guzzle 8 rejects or ignores these options
  next to a `handler`, so the bundle no longer injects one and adds its middleware after creation through
  `getConfig('handler')`, after the client's own configurator if it has one.

## [2.1.2] - 2026-10-21

### Fixed

- With `baggage.allowed_hosts` set, curl NTLM authentication works again: a request with `auth_ntlm` (of the
  request, `withOptions()` or `default_options`) gets no baggage of the bundle and leaves its redirects to the
  transport. The bundle followed them itself with `max_redirects` 0, and libcurl does not finish an NTLM
  handshake with `CURLOPT_MAXREDIRS` 0 (2.1.1 blamed `AsyncResponse` for it).

## [2.1.1] - 2026-10-21

### Security

- `baggage.allowed_hosts` no longer sends the bundle's baggage to a host off the list through a redirect: with a list
  set, a Symfony HttpClient request that carries it follows its redirects in the bundle, hop by hop, with the native
  transport's rules (any 3xx but 304, method change on 301–303, credentials — the client's defaults and NTLM
  included — to the first origin only, `max_redirects` of the request, `withOptions()` or `default_options`).
- The host is read with Symfony HttpClient's own URL parser (international names in punycode), not `parse_url()`.

### Changed

- With a list set, every Symfony HttpClient response is an `AsyncResponse` (`getInfo()` has `previous_info`;
  curl NTLM authentication fails with a `LogicException`, as through `RetryableHttpClient`), and requests that carry
  the bundle's baggage to a listed host report idle timeouts as `TransportException`, not `TimeoutException`.
  Their redirects differ from the transport's: the `query` option is not merged into the `Location`
  again; a seekable stream body is sent again from where it stood, and a body that cannot be sent again ends the
  chain with the redirect response.
- Without a list, the HttpClient decorator is the one of 2.0 again (no redirect handling, no URL parsing).
- Guzzle: no change; the README now says where a hand-built stack must put the middleware.

## [2.1.0] - 2026-10-21

### Added

- `msstc4symfony_tracing.baggage.allowed_hosts`: the hosts outgoing Symfony HttpClient and Guzzle requests send
  W3C baggage to — host names, IP addresses or `*.domain` wildcards. Other hosts get no `baggage` from the bundle;
  a `baggage` the caller set is sent unchanged, `traceparent` and `tracestate` go everywhere. Not set: every host,
  as in 2.0. A literal invalid entry fails the container build.
- `Application\BaggageRecipients`, an optional last argument of `TraceGuzzleMiddleware`.

## [2.0.0] - 2026-10-20

Test and documentation fixes only since 2.0.0-rc.1.

## [2.0.0-rc.1] - 2026-10-20

The bundle propagates W3C Trace Context and W3C Baggage only; the trace of a unit of work is an
immutable `TraceContext` behind a three-method holder. Configuration keys are unchanged.

### Upgrading

- Drain the Messenger async transports before switching producers or consumers: `TraceStamp` moved
  (`Messenger\Stamp\TraceStamp` → `Framework\Messenger\TraceStamp`) and changed shape, so a message stamped by
  1.x fails to decode on 2.0 (`MessageDecodingFailedException`) and the other way round.
- Update `framework.messenger.buses.*.middleware` and `sentry.options.integrations` to the new class names.

### Changed

- Classes moved into the canonical layers of bundle-standard v3 (`Msstc4Symfony\TracingBundle\…`):
  - `Storage\W3c\TraceParent`, `Storage\W3c\TraceState` → `Domain\ValueObject\TraceParent`, `Domain\ValueObject\TraceState`;
  - `Storage\TraceContext` → `Domain\ValueObject\TraceContext` (rewritten, see below);
  - `Storage\RequestIdServiceInterface` → `Application\Port\TraceContextHolderInterface` (rewritten, see below);
  - `Storage\RequestIdService` → `Infrastructure\Context\InMemoryTraceContextHolder` (`@internal`);
  - `HttpClient\HttpClientDecorator` → `Infrastructure\HttpClient\TraceHttpClientDecorator` (`@internal`);
  - `GuzzleHttp\RequestIdGuzzleHandler` → `Infrastructure\Guzzle\TraceGuzzleMiddleware`;
  - `GuzzleHttp\ChainedClientConfigurator` → `Infrastructure\Guzzle\ChainedClientConfigurator` (`@internal`);
  - `EventListener\HTTPRequestListener` → `Presentation\EventListener\HttpTraceListener` (`@internal`);
  - `EventListener\ConsoleSubscriber` → `Presentation\EventListener\ConsoleTraceSubscriber` (`@internal`);
  - `Messenger\Middleware\IncomingStampMiddleware`, `OutgoingStampMiddleware` →
    `Framework\Messenger\IncomingTraceMiddleware`, `OutgoingTraceMiddleware` (update `buses.*.middleware`);
  - `Messenger\Stamp\TraceStamp` → `Framework\Messenger\TraceStamp`;
  - `Messenger\EventListener\WorkerTraceSubscriber` → `Framework\Messenger\WorkerTraceSubscriber` (`@internal`);
  - `Monolog\RequestIdProcessor` → `Framework\Monolog\TraceLogProcessor` (`@internal`);
  - `Sentry\Integration\TracingIntegration` → `Framework\Sentry\TracingIntegration` (update `sentry.options.integrations`);
  - `DependencyInjection\Compiler\*Pass` → `DependencyInjection\CompilerPass\*Pass` (`@internal`).
- `request-id` and `request-from` are gone: no longer read from requests, sent on responses or outgoing
  requests, or logged (`request_id`, `request_from`). The caller travels as the baggage member
  `msstc4symfony.caller` and is logged as `caller`.
- The response carries `traceresponse` (W3C Trace Context Level 2) instead of `request-id`.
- `RequestIdServiceInterface` is replaced by `TraceContextHolderInterface`: `current(): TraceContext`,
  `replace(TraceContext)`, `reset()`. `snapshot()` / `restore()` become `current()` / `replace()`;
  request id, request-from and generate methods are removed.
- `TraceContext` is immutable with a private constructor: `start()`, `continuing()`, `withBaggage()`,
  `withChildSpan()`, `outgoing()`, `caller()`; every field is set on creation (no lazily created ids).
- `TraceParent::$parentId` is renamed `$spanId`; `TraceParent::toRequestId()` is removed.
- `TraceStamp` is `(traceParent, ?traceState, ?baggage)`; `$requestId` and `$requestFrom` are removed.
- A consumed message without a `TraceStamp` starts a new trace; a `sync://` message without one keeps the
  dispatching unit's runtime id and runs as its child span.
- Sentry events carry `runtime_id` and `caller` in `extra` (`request_id`, `request_from` removed).
- `symfony/uid` is no longer required.
- bundle-standard v3: deptrac layers, `#[Override]` checked by PHPStan, reusable workflow `@v3.0.0`.

### Added

- `Domain\ValueObject\Baggage`: W3C Baggage received over HTTP and in `TraceStamp` is kept and forwarded
  (invalid members dropped, 180 members / 8192 bytes); a caller-set `baggage` (Sentry's included) is sent as
  given, followed by the caller member and the received members it does not name.
- `Application\CallerName`, `Domain\ValueObject\OutgoingTrace`.
- `TraceParent::HEADER`, `TraceParent::RESPONSE_HEADER`, `TraceState::HEADER`, `Baggage::HEADER`.
- README «Public API»; bundle-standard `doc/decoration-priorities.md` registers the `http_client.transport`
  priority (-15).

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

[2.2.0]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v2.2.0
[2.1.3]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v2.1.3
[2.1.2]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v2.1.2
[2.1.1]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v2.1.1
[2.1.0]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v2.1.0
[2.0.0]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v2.0.0
[2.0.0-rc.1]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v2.0.0-rc.1
[1.3.0]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v1.3.0
[1.2.0]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v1.2.0
[1.1.0]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/msstc4symfony/tracing-bundle/releases/tag/v1.0.0
