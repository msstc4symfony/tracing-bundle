# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

Runtime requirements: PHP >= 8.4, Symfony 7.4 / 8.x.

## Reporting a Vulnerability

Do **not** open public issues for security problems. Use:

1. **GitHub Security Advisory** (preferred):
   <https://github.com/msstc4symfony/tracing-bundle/security/advisories/new>
2. **Email**: `maxim.shamaev@gmail.com`

Include the bundle, PHP and Symfony versions, a reproducer and the impact. You will get an
acknowledgement within **7 days**; please allow 30–90 days before public disclosure.

## Threat Model

### 1. Incoming trace headers are untrusted input

`request-id` and `request-from` are taken from the incoming request as-is and are then
written to logs, Sentry events, response headers and outgoing requests. A caller can choose
their values. Do not use them for authorization or as keys in anything security-relevant.
Terminate the headers at the edge (reverse proxy / API gateway) if external clients must not
be able to set them, and keep log pipelines tolerant to arbitrary header content.

### 2. Trace headers leave the service

Every outgoing HTTP request carries `request-id` and `request-from`
(`APPLICATION_NAME:COMPONENT_NAME`), including requests to third-party APIs. Both reveal
internal naming. If that matters, set the header explicitly on such requests — the bundle
never overwrites a header the caller set.

### 3. Long-running workers

The context lives in a shared service. It is reset on `kernel.reset`, on every main HTTP
request, on every console command and around every consumed Messenger message (when the
middleware is enabled). Custom loops outside those entry points must call
`RequestIdServiceInterface::reset()` themselves, or one unit of work inherits another's id.
