# Tracing Symfony bundle

![Build Status](https://github.com/msstc4symfony/tracing-bundle/actions/workflows/checks.yml/badge.svg?branch=main)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Receives, keeps and forwards a trace context so one user action can be followed through
every service it touches:

* `request ID` — identifier of the request, shared by every service on its path;
* `request from` — `application:component` of the service that sent the request;
* `runtime ID` — identifier of the current unit of work in this service (HTTP request,
  console command, consumed message).

| Channel | In | Out |
|---|---|---|
| HTTP | `request-id` / `request-from` request headers, W3C `traceparent` / `tracestate` | same headers on the response and on every Symfony HttpClient / Guzzle request; `traceparent` / `tracestate` on outgoing requests |
| Messenger | `RequestIdStamp`, `TraceContextStamp` on consumed messages | `RequestIdStamp` on dispatched messages; `TraceContextStamp` (opt-in) |
| Logs | — | `runtime_id`, `request_id`, `request_from` in every Monolog record's `extra`; `trace_id`, `span_id` with W3C on |
| Sentry | — | `runtime_id`, `request_id`, `request_from` in every event's `extra`; `trace_id`, `span_id` tags with W3C on (opt-in) |

## Compatibility

| Bundle | PHP  | Symfony       |
|--------|------|---------------|
| 1.x    | 8.4+ | 6.4, 7.x, 8.x |

## Installation

The package is not on Packagist yet, so register its GitHub repository first:

```sh
composer config repositories.msstc4symfony-tracing vcs https://github.com/msstc4symfony/tracing-bundle
composer require msstc4symfony/tracing-bundle
```

Symfony Flex registers the bundle. Otherwise add it to `config/bundles.php`:

```php
return [
    Msstc4Symfony\TracingBundle\TracingBundle::class => ['all' => true],
];
```

`request from` is built from `APPLICATION_NAME` and `COMPONENT_NAME` (default `unknown`):

```
APPLICATION_NAME=shop
COMPONENT_NAME=api
```

## What is wired automatically

* HTTP requests and console commands start a new trace (sub-requests keep the main one).
* Symfony HttpClient: every framework client, default and scoped, sends the trace headers
  (the shared `http_client.transport` is decorated). Clients created outside FrameworkBundle
  are not covered.
* Guzzle: every container service whose class implements `GuzzleHttp\ClientInterface` gets a
  handler-stack middleware, unless the service already has a configurator.
* Monolog: the processor is registered for all channels.
* The context is reset on `kernel.reset`, so long-running workers (RoadRunner, FrankenPHP,
  Messenger) never carry an id into the next unit of work.

Headers already set by the caller are never overwritten (`request-id` and `request-from` independently).

## W3C Trace Context (OpenTelemetry interop)

On by default since 1.1. It only adds headers, log keys and Sentry tags; `request-id` handling is unchanged.
Configuration: [doc/w3c_trace_context.yaml](doc/w3c_trace_context.yaml); switch it off with
`msstc4symfony_tracing: { w3c_trace_context: false }`.

Incoming request (main requests only):

* a valid [`traceparent`](https://www.w3.org/TR/trace-context/#traceparent-header) is kept:
  trace id, parent span id, flags (sampled and random; reserved bits are cleared, as the spec
  says for version `00`). This request gets its own span id;
* an invalid one is ignored, as the spec says: unknown format, upper-case hex, version `ff`,
  version `00` with extra fields, all-zero trace or parent id, the header sent twice. Its
  `tracestate` is ignored with it. Higher versions are read by their first four fields and
  forwarded as version `00`;
* `tracestate` entries are forwarded unchanged (repeated headers joined with `,`, blank entries
  and surrounding spaces removed). The whole header is ignored when an entry breaks the spec's
  `key=value` grammar, a key repeats or there are more than 32 entries. Above 512 characters
  entries are dropped as the spec suggests: those over 128 characters first, then from the end;
* no `request-id` but a valid `traceparent`: the request id becomes the trace id in UUID
  layout (`4bf92f3577b34da6a3ce929d0e0e4736` → `4bf92f35-77b3-4da6-a3ce-929d0e0e4736`; its
  version/variant bits are arbitrary, so a strict UUIDv4 validator rejects it), and
  `request-from` is the header or `unknown`. An OpenTelemetry caller's trace then shares its id
  with this bundle's request id. With a `request-id` header both ids are kept as received.

Outgoing (Symfony HttpClient, Guzzle, Messenger when `messenger: true`): `traceparent` with the
same trace id and flags and a new span id for every request or message, plus the received
`tracestate`. Without an incoming trace the unit starts one: random 16-byte trace id, 8-byte
span id, flags `01`. A `traceparent` set by the caller is kept, and so is a `tracestate` set by
the caller, even next to the bundle's own `traceparent` (dropping either would lose data the
caller chose to send). No `traceresponse` header is sent.

Both headers, including a `tracestate` received from outside, go to every host the clients
call, third-party APIs included (see the spec's
[privacy section](https://www.w3.org/TR/trace-context/#privacy-considerations)). Set
`traceparent` / `tracestate` yourself on a request to override them, or switch W3C off.

A sync (`sync://`) message runs as a child span of the dispatching unit even without a
stamp. A custom `RequestIdServiceInterface` gets W3C only if it also implements
`W3cTraceContextInterface`; otherwise the W3C state stays on the bundle's own storage and is
reset only by `kernel.reset`.

Messages are not stamped by default: a consumer running tracing-bundle 1.0 (or no tracing
bundle) cannot decode a message with an unknown stamp class, and both Messenger serializers
fail on it. Consumers on 1.1 read `TraceContextStamp` whatever the setting.

Long-running workers reset the W3C context with the rest of the trace: every main request,
consumed message and `kernel.reset` starts clean.

## Opt-in integrations

**Messenger** — middleware must be listed on the buses ([doc/messenger.yaml](doc/messenger.yaml)).
A message consumed by a worker runs in its sender's trace, which stays active for the
worker's own logs and for messages released by `dispatch_after_current_bus`, and is cleared
before the next message. A message handled synchronously (`sync://`) returns to the caller's
trace afterwards. A batch handler processes the whole batch in one message's trace.

**Sentry** — add the integration ([doc/sentry.yaml](doc/sentry.yaml)). With W3C on (1.2+),
every event is also tagged `trace_id` and `span_id` — the same values as the log keys, read from
the current unit of work when the event is captured, so events are searchable by the trace id
of their logs: search `trace_id:<id>` (the tag), not `trace:<id>`, which is Sentry's own trace.
If the application already set either tag on the event, the bundle adds neither, so the pair
never mixes the two sources.

The event's `trace` context is left to Sentry: it holds Sentry's own trace (continued from
`sentry-trace` / `baggage`; sentry/sentry 4.32 does not parse `traceparent`)
and links errors to Sentry's transactions, so overwriting it would break Sentry tracing. Its
`trace_id` therefore usually differs from the `trace_id` tag. The bundle does not register
Sentry's external propagation context either: that hook replaces Sentry's own propagation
(outgoing `sentry-trace` / `baggage`, dynamic sampling) and is the one the OTLP integration uses.

## Usage

```php
final class Service
{
    public function __construct(
        private RequestIdServiceInterface $trace,
    ) {
    }

    public function doSomething(): void
    {
        $runtimeId = $this->trace->getRuntimeId();
        $requestId = $this->trace->getRequestId();
        $requestFrom = $this->trace->getRequestFrom();
    }
}
```

## Local development

```sh
COMPOSER=composer-ci.json composer install   # optional libraries + CI-only tools
COMPOSER=composer-ci.json make check
make test
make fix
```

## License

MIT, see [LICENSE](LICENSE).
