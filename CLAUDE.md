# CLAUDE.md

Guidance for Claude Code in this repository. Deep references live under `.claude/docs/`.

## What this is

Symfony bundle (`msstc4symfony/tracing-bundle`, namespace `Msstc4Symfony\TracingBundle`)
that propagates W3C Trace Context and W3C Baggage (plus a per-unit runtime id and the caller as baggage member
`msstc4symfony.caller`) across HTTP (in and out), Messenger, Monolog and Sentry. PHP >= 8.2, Symfony 7.4 / 8.x.
Library code only.

## Common commands

Develop against the CI profile: `COMPOSER=composer-ci.json composer install`.

- `make check` — `php -l`, PHPStan level 10, PHP-CS-Fixer, `composer validate --strict`,
  `composer audit`, Rector dry-run, deptrac. Run as `COMPOSER=composer-ci.json make check`.
- `make test` — unit + integration suites; `make infection`, `make fix`, `make regenerate-baseline`.

## Architecture in 60 seconds

- Canonical hex layers (bundle-standard v3): `Domain/ValueObject` (`TraceContext`, `TraceParent`, `TraceState`,
  `Baggage`, `OutgoingTrace` — immutable), `Application` (`Port\TraceContextHolderInterface`, `CallerName`),
  `Infrastructure`, `Presentation`, `Framework`, `DependencyInjection`.
- `InMemoryTraceContextHolder` holds the current `TraceContext`; `kernel.reset` tag. Nested units:
  `$outer = current(); replace($outer->withChildSpan()); try … finally replace($outer)`.
- Entry points: `HttpTraceListener` (main request only, resets only when no `kernel.reset` ran since the previous
  main request; `continuing()` keeps the runtime id of early logs; sends `traceresponse`), `ConsoleTraceSubscriber`,
  `IncomingTraceMiddleware` (messages with `ReceivedStamp`).
- Exits: `TraceHttpClientDecorator` on `http_client.transport` (priority -15, registry in
  `bundle-standard/doc/decoration-priorities.md`), Guzzle middleware via `GuzzlePass` (Guzzle 7 and 8),
  `OutgoingTraceMiddleware` (`TraceStamp(traceParent, ?traceState, ?baggage)`), `TraceLogProcessor`,
  `TracingIntegration` (Sentry tags `trace_id`/`span_id`).
- Config `msstc4symfony_tracing`: `application_name` / `component_name` (env defaults) → `CallerName`.
- `Resources/config/services.php` registers optional integrations behind `interface_exists`.
  Rector's `FromServicePublicToDefaultsPublicRector` and `ServiceSettersToSettersAutodiscoveryRector`
  are skipped on purpose — they rewrite this file into public autodiscovery.

Details: `.claude/docs/architecture.md`. **Read `.claude/docs/known-issues.md` before chasing
a "weird" failure.**

## Pointers

- `.claude/docs/architecture.md` — wiring, layers, decoration order.
- `.claude/docs/conventions.md` — guards, header handling, reset rules.
- `.claude/docs/testing.md` — unit layout, real-kernel test, mock transport.
- `.claude/docs/tooling.md` — manifests, `make check`, Rector skips.
- `.claude/docs/ci.md` — reusable workflow.
- `.claude/docs/known-issues.md` — what was broken and why, CI/prefer-lowest findings, declined review items.
