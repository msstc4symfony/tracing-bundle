# Tracing Symfony bundle

![Build Status](https://github.com/msstc4symfony/tracing-bundle/actions/workflows/checks.yml/badge.svg?branch=main)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Receives, keeps and forwards a [W3C Trace Context](https://www.w3.org/TR/trace-context/) and
[W3C Baggage](https://www.w3.org/TR/baggage/), so one user action can be followed through every
service it touches, OpenTelemetry-instrumented ones included:

* `trace_id` — the trace, shared by every service on its path;
* `span_id` / `parent_span_id` — this unit of work's span and the span of the unit that called it;
* `caller` — `application:component` of the calling service, sent as the baggage member
  `msstc4symfony.caller`;
* `runtime_id` — identifier of the current unit of work in this service (HTTP request,
  console command, consumed message).

| Channel | In | Out |
|---|---|---|
| HTTP | `traceparent`, `tracestate`, `baggage` request headers | `traceresponse` on the response; `traceparent`, `tracestate`, `baggage` on every Symfony HttpClient / Guzzle request (`baggage` only to `allowed_hosts` when set) |
| Messenger | `TraceStamp` on consumed messages | `TraceStamp` on dispatched messages |
| Logs | — | `runtime_id`, `trace_id`, `span_id`, `parent_span_id`, `caller` in every Monolog record's `extra` |
| Sentry (opt-in) | — | `runtime_id`, `caller` in every event's `extra`; `trace_id`, `span_id` tags |

## Compatibility

| Bundle  | PHP  | Symfony       |
|---------|------|---------------|
| 2.0     | 8.2+ | 7.4, 8.x      |
| 1.3     | 8.2+ | 7.4, 8.x      |
| 1.0–1.2 | 8.4+ | 7.4, 8.x      |

Symfony 8.x needs PHP 8.4; on PHP 8.2 and 8.3 Composer installs Symfony 7.4.

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

The caller name sent downstream is `application_name:component_name`. Both default to the `APPLICATION_NAME` /
`COMPONENT_NAME` environment variables, or `unknown` when those are not set:

```yaml
# config/packages/msstc4symfony_tracing.yaml
msstc4symfony_tracing:
  application_name: '%env(default:msstc4symfony_tracing.unknown:APPLICATION_NAME)%'
  component_name: '%env(default:msstc4symfony_tracing.unknown:COMPONENT_NAME)%'
```

## What is wired automatically

* HTTP requests and console commands start a new trace (sub-requests keep the main one).
  Records logged before the request listener (kernel boot, request listeners above priority
  2048) keep their ids: the listener resets the trace unless `kernel.reset` (or a fresh PHP
  process) opened a new one since the previous main request. An
  incoming `traceparent` replaces the trace id from that point on; the runtime id stays.
* Symfony HttpClient: every framework client, default and scoped, sends the trace headers
  (the shared `http_client.transport` is decorated). Clients created outside FrameworkBundle
  are not covered.
* Guzzle 7 and 8: every container service whose class implements `GuzzleHttp\ClientInterface`
  gets a handler-stack middleware and keeps its own configurator:
  * a client created by `GuzzleHttp\Client::__construct()` gets it in its `handler` config (its
    own `HandlerStack`, or Guzzle's default stack); a bare callable `handler` is left as is;
  * a client built by a factory or with its own constructor gets it after creation through
    `getConfig('handler')` (every client on Guzzle 7, `GuzzleHttp\Client` on Guzzle 8);
  * any other Guzzle 8 client cannot be reached and is named in the container compiler log
    (`var/cache/<env>/*Compiler.log`, debug mode); create it with the handler from
    `TraceGuzzleMiddleware::decorateHandler()`, or push `TraceGuzzleMiddleware::middleware()`
    onto its stack yourself.
* Monolog: the processor is registered for all channels.
* The context is reset on `kernel.reset`, so long-running workers (RoadRunner, FrankenPHP,
  Messenger) never carry an id into the next unit of work.

Headers already set by the caller are never overwritten; see Baggage below for the one addition.

## W3C Trace Context

Always on, nothing to configure.

Incoming request (main requests only):

* a valid [`traceparent`](https://www.w3.org/TR/trace-context/#traceparent-header) is continued:
  trace id, parent span id, flags (sampled and random; reserved bits are cleared, as the spec
  says for version `00`). This request gets its own span id;
* an invalid one is ignored, as the spec says: unknown format, upper-case hex, version `ff`,
  version `00` with extra fields, all-zero trace or parent id, the header sent twice. Its
  `tracestate` is ignored with it, and the request starts a new trace. Higher versions are read
  by their first four fields and forwarded as version `00`;
* `tracestate` entries are forwarded unchanged (repeated headers joined with `,`, blank entries
  and surrounding spaces removed). The whole header is ignored when an entry breaks the spec's
  `key=value` grammar, a key repeats or there are more than 32 entries. Above 512 characters
  entries are dropped as the spec suggests: those over 128 characters first, then from the end.

Response: [`traceresponse`](https://www.w3.org/TR/trace-context-2/#traceresponse-header)
(Trace Context Level 2) names this request's span, so a caller without `traceparent` learns the
trace id the service started. A `traceresponse` the application set is kept.

Outgoing (Symfony HttpClient, Guzzle, Messenger): `traceparent` with this unit's own span —
the same trace id, flags and span id the unit logs — plus the received `tracestate`. Two calls
from one unit therefore carry the same parent id. Without an incoming trace the unit starts
one: random 16-byte trace id, 8-byte span id, flags `01`. A `traceparent` set by the caller is
kept with no `tracestate` added; a `tracestate` set by the caller is kept too.

Logs: `span_id` is the unit's own span, `parent_span_id` the span id of the unit that started it
(the `traceparent` received over HTTP or in a `TraceStamp`), `null` when the unit started the
trace. As outgoing calls send the unit's own span, a receiver's `parent_span_id` is a `span_id` in
the sender's logs, so the logs of every service rebuild the call tree.

## W3C Baggage

Received `baggage` is kept for the unit of work and forwarded with every outgoing request and
message, so a chain of services that read it is not broken by one that does not. It is kept
even when `traceparent` is missing or invalid. Members that break the
[grammar](https://www.w3.org/TR/baggage/#definition) are dropped one by one, the rest are kept;
above 180 members or 8192 bytes members are dropped from the end. Values are percent-decoded
on receipt and percent-encoded when sent.

The bundle adds one member, `msstc4symfony.caller=<application_name>:<component_name>`, to
what it sends: the receiver logs it as `caller`. A `baggage` header the caller set (by hand, or
Sentry's `sentry-*` members set on the client) is sent as given, followed by the members it does
not name: this service as caller and the received ones. Only `caller` is logged, never the rest
of the baggage.

Guzzle with Sentry's `GuzzleTracingMiddleware`: that middleware replaces `baggage` with Sentry's
members. Push it so it runs before this bundle's middleware (further down the stack) — then the
bundle sees Sentry's header and adds the rest; run after it, Sentry drops the caller and the
received members.

Baggage and trace headers, including baggage received from outside, go to every host the clients
call, third-party APIs included (see the specs' privacy sections:
[Trace Context](https://www.w3.org/TR/trace-context/#privacy-considerations),
[Baggage](https://www.w3.org/TR/baggage/#privacy-considerations)). Set the headers yourself on a
request to override them, or list the hosts that may receive baggage:

```yaml
msstc4symfony_tracing:
  baggage:
    allowed_hosts: ['orders', 'payments.internal', '*.svc.cluster.local', '10.0.0.7', '[fd00::7]']
```

* not set (the default): every host gets baggage. The key cannot be set to `~`; leave it out instead;
* a list: only the hosts it names. An entry is a host name (case-insensitive, any port, a trailing dot
  ignored), an IP address (IPv6 in brackets, any spelling) or `*.example.com`, which matches every subdomain
  of `example.com` but not `example.com` itself. `[]` sends baggage to no host. A list in a later file
  (`when@prod`) replaces the earlier one. An invalid entry (a port, a URL, a `*` elsewhere, `*.` over
  numbers, non-ASCII: write IDNs in punycode) fails the container build. Entries may use `%env()%`, the
  list as a whole cannot; such an entry is checked when the HTTP client is first built, and an invalid or
  unset value makes building it fail;
* a host not on the list gets no `baggage` from the bundle, not even `msstc4symfony.caller`. A `baggage`
  header the caller set (Sentry's, for one) is sent unchanged. `traceparent` and `tracestate` still go to
  every host, and Messenger messages always carry the baggage.

The host is the one of the request URL (Symfony HttpClient: of the request's or `withOptions()`' `base_uri` for
a relative URL; Guzzle: of the request URI). A relative URL resolved by a `base_uri` the transport itself was
created with (a hand-made `http_client.transport`) has no host here and gets no baggage.

Upgrading from 1.x: `TraceStamp` moved to `Framework\Messenger\TraceStamp`, and a message stamped
by one major cannot be decoded by the other. Drain the async transports (or stop producers until
the consumers are upgraded, and the other way round) before switching.

Messenger: a dispatched message carries one `TraceStamp` (`traceparent`, `tracestate`,
`baggage` as header strings); the consumer continues that trace. Every consumer must run this
bundle: Messenger serializers cannot decode a message with an unknown stamp class. A consumed
message without the stamp starts a new trace; a sync (`sync://`) message without it runs as a
child span of the dispatching unit, in the same runtime.

Long-running workers reset the context with every main request, consumed message and
`kernel.reset`.

## Opt-in integrations

**Messenger** — middleware must be listed on the buses ([doc/messenger.yaml](doc/messenger.yaml)).
A message consumed by a worker runs in its sender's trace, which stays active for the
worker's own logs and for messages released by `dispatch_after_current_bus`, and is cleared
before the next message. A message handled synchronously (`sync://`) returns to the caller's
trace afterwards. A batch handler processes the whole batch in one message's trace.

**Sentry** — add the integration ([doc/sentry.yaml](doc/sentry.yaml)). Every event is also
tagged `trace_id` and `span_id` — the same values as the log keys, read from
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
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;

final class Service
{
    public function __construct(
        private TraceContextHolderInterface $trace,
    ) {
    }

    public function doSomething(): void
    {
        $context = $this->trace->current();
        $traceId = $context->traceParent->traceId;
        $caller = $context->caller();
    }
}
```

A nested unit of work inside one process (an application action, a batch item) runs in its
own span, a child of the current one:

```php
$outer = $this->trace->current();
$this->trace->replace($outer->withChildSpan());
try {
    // nested unit
} finally {
    $this->trace->replace($outer);
}
```

## Public API

Everything else is `@internal`.

| Kind | Name |
|---|---|
| Trace context (immutable) | `Domain\ValueObject\TraceContext`, `TraceParent`, `TraceState`, `Baggage`, `OutgoingTrace` |
| Port | `Application\Port\TraceContextHolderInterface` (`current()`, `replace()`, `reset()`) |
| Caller name, baggage hosts | `Application\CallerName`, `Application\BaggageRecipients` |
| Messenger | `Framework\Messenger\IncomingTraceMiddleware`, `OutgoingTraceMiddleware`, `TraceStamp` |
| Sentry | `Framework\Sentry\TracingIntegration` |
| Guzzle | `Infrastructure\Guzzle\TraceGuzzleMiddleware` (`decorateHandler()`, `middleware()`) |
| Bundle | `TracingBundle`; config keys `msstc4symfony_tracing.application_name` / `.component_name` / `.baggage.allowed_hosts` |
| Wire format | headers `traceparent`, `tracestate`, `baggage`, `traceresponse`; baggage member `msstc4symfony.caller`; log keys `runtime_id`, `trace_id`, `span_id`, `parent_span_id`, `caller` |

## Local development

```sh
COMPOSER=composer-ci.json composer install   # optional libraries + CI-only tools
COMPOSER=composer-ci.json make check
make test
make fix
```

## License

MIT, see [LICENSE](LICENSE).
