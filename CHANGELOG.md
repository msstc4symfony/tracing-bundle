# Changelog

## 1.3.1

- `HTTPRequestListener` resets the context unless `kernel.reset` ran since the previous main
  request (it is now `kernel.reset`-tagged itself). 1.3.0 compared runtime ids, so a console
  command or a received message handled in-process between requests without `kernel.reset`
  leaked its ids into the next request.
- `GuzzlePass` reads the client config from argument 0 only: named arguments are already
  resolved when it runs.
- Internal: tighter Guzzle tests (middleware added once, own configurator runs first), shared
  `GuzzleOptions` type alias, `ChainedClientConfigurator` typed as a `void` configurator.

## 1.3.0

- Guzzle 8 support (Guzzle 7 keeps working). `GuzzleHttp\ClientInterface::getConfig()` is gone in 8,
  so `GuzzlePass` now puts the middleware into the `handler` config of every client created by
  `GuzzleHttp\Client::__construct()`. Clients built by a factory or with their own constructor are
  still configured through `getConfig()`; a Guzzle 8 client without it is named in the container
  compiler log instead of failing.
- Guzzle clients with their own service configurator are traced too; their configurator is kept
  (and, for factory-built clients, run before the tracing one).
- New API: `RequestIdGuzzleHandler::decorateHandler()` (the handler to create a client with) and
  the now public `RequestIdGuzzleHandler::middleware()`. `addHandler()` throws a `LogicException` for
  a client without `getConfig()` instead of a fatal error.
- Records logged before `HTTPRequestListener` keep their request, trace and runtime ids for the rest
  of the request: the listener resets the context only when it still holds the previous main
  request's. The listener now runs at priority 2048 (was 100), ahead of FrameworkBundle's request
  listeners, so their logs already carry an incoming `request-id` / `traceparent`.
- `symfony/service-contracts` (`ResetInterface`) is declared as a dependency (`^2.5|^3`).

## 1.2.0

- Sentry: with W3C Trace Context on, `TracingIntegration` tags every event with `trace_id` and
  `span_id` (the values Monolog records carry), taken from the current unit of work when the
  event is captured, so nothing carries over between requests or messages in a worker. If the event
  already has either tag, neither is added. Sentry's own `trace` context and propagation are not
  touched.
- `TracingIntegration` takes the W3C context as a new optional constructor argument.

## 1.1.0

- W3C Trace Context for OpenTelemetry interop, on by default (`w3c_trace_context`, config root
  `msstc4symfony_tracing`). It adds headers and log keys only; `request-id` / `request-from`
  behave as before.
  - Incoming `traceparent` is validated per spec (invalid ones ignored) and kept with its
    `tracestate` (entries validated, at most 32, truncated to 512 characters as the spec says).
  - Only the sampled and random trace flags are forwarded; reserved bits are cleared.
  - Without `request-id`, a valid `traceparent` gives the request id: its trace id in UUID spelling.
  - HttpClient and Guzzle send `traceparent` (same trace, new span id per request, flags kept)
    and `tracestate`; a new trace is started when none was received.
  - Messenger: `TraceContextStamp` is read on consumed messages; stamping dispatched messages is
    opt-in (`w3c_trace_context.messenger: true`) because consumers on 1.0 cannot decode it.
  - Monolog `extra` gains `trace_id` and `span_id`.
- The bundle now has a configuration tree under `msstc4symfony_tracing` (the parameter prefix
  it already used). A leftover empty `tracing:` key from 1.0 must be removed.
- An application storage that implements both `RequestIdServiceInterface` and
  `W3cTraceContextInterface` is used for W3C too (`W3cTraceContextPass`).
- New API: `Storage\W3cTraceContextInterface` (implemented by `RequestIdService`, aliased only
  while enabled), `Storage\W3c\TraceParent`, `Storage\W3c\TraceState`,
  `Messenger\Stamp\TraceContextStamp`; header constants `HTTPRequestListener::TRACEPARENT_HEADER`
  / `TRACESTATE_HEADER`. Integrations take the W3C context as a new optional constructor argument.

## 1.0.0

First release as `msstc4symfony/tracing-bundle` (`Msstc4Symfony\TracingBundle`), MIT.

- Symfony HttpClient tracing now actually works: the shared transport is decorated, so every
  framework client (default and scoped) sends the headers; `withOptions()` keeps tracing.
- Guzzle clients get the tracing middleware through a service configurator and keep their type.
- Messenger: middleware must be listed on the buses (see `doc/messenger.yaml`); consumed
  messages run in the sender's trace, kept for the worker's logs and deferred dispatches and
  closed when the worker moves on (next received message, `WorkerRunningEvent`, worker
  stop); idle ticks no longer regenerate the trace, so between messages the worker runs in
  one stable fresh trace; synchronous (`sync://`) handling restores the caller's trace.
- The context resets on `kernel.reset`, on main HTTP requests and on console commands;
  sub-requests keep the main trace.
- `request from` comes from `APPLICATION_NAME` / `COMPONENT_NAME` (was
  `METRICS_APPLICATION_NAME` / `METRICS_COMPONENT_NAME`).
- PHP >= 8.4, Symfony 6.4 / 7.x / 8.x; no YAML dependency.
