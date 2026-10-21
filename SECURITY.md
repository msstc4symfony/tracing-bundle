# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 2.x     | :white_check_mark: |
| 1.x     | :x:                |

Runtime requirements: PHP >= 8.2, Symfony 7.4 / 8.x.

## Reporting a Vulnerability

Do **not** open public issues for security problems. Use:

1. **GitHub Security Advisory** (preferred):
   <https://github.com/msstc4symfony/tracing-bundle/security/advisories/new>
2. **Email**: `maxim.shamaev@gmail.com`

Include the bundle, PHP and Symfony versions, a reproducer and the impact. You will get an
acknowledgement within **7 days**; please allow 30–90 days before public disclosure.

## Threat Model

### 1. Incoming trace headers are untrusted input

`traceparent`, `tracestate` and `baggage` are taken from the incoming request and are then
written to logs (trace and span ids, the `caller` baggage member), Sentry events, the response
(`traceresponse`) and outgoing requests and messages. A caller can choose their values. They are
validated against the W3C grammars (no control characters, quotes, separators or line breaks
reach a header), but never use them for authorization or as keys in anything security-relevant.
Terminate the headers at the edge (reverse proxy / API gateway) if external clients must not be
able to set them, and keep log pipelines tolerant to arbitrary `caller` content.

### 2. Trace headers and baggage leave the service

Every outgoing HTTP request and dispatched message carries `traceparent`, the received
`tracestate` and the received `baggage` plus `msstc4symfony.caller`
(`APPLICATION_NAME:COMPONENT_NAME`), including requests to third-party APIs. Baggage received
from a caller can therefore reach hosts that caller never talked to, and the caller member
reveals internal naming. If that matters, list the hosts that may receive baggage in
`msstc4symfony_tracing.baggage.allowed_hosts` (other hosts get no `baggage` from the bundle; messages
are not affected), set the headers explicitly on such requests — the bundle never replaces a header
the caller set — or strip `baggage` at the egress proxy. `traceparent` and `tracestate` go to every
host whatever the list says.

### 3. Long-running workers

The context lives in a shared service. It is reset on `kernel.reset`, on every main HTTP
request, on every console command and around every consumed Messenger message (when the
middleware is enabled). Custom loops outside those entry points must call
`TraceContextHolderInterface::reset()` themselves, or one unit of work inherits another's trace
and baggage.
