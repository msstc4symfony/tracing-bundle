# CLAUDE.md

Guidance for Claude Code in this repository. Deep references live under `.claude/docs/`.

## What this is

Symfony bundle (`msstc4symfony/tracing-bundle`, namespace `Msstc4Symfony\TracingBundle`)
that propagates a trace context — request id, caller (`request from`) and runtime id —
across HTTP (in and out), Messenger, Monolog and Sentry. PHP >= 8.4, Symfony 6.4 / 7.x /
8.x. Library code only.

## Common commands

Develop against the CI profile: `COMPOSER=composer-ci.json composer install`.

- `make check` — `php -l`, PHPStan level 10, PHP-CS-Fixer, `composer validate --strict`,
  `composer audit`, Rector dry-run, deptrac. Run as `COMPOSER=composer-ci.json make check`.
- `make test` — unit + integration suites; `make infection`, `make fix`, `make regenerate-baseline`.

## Architecture in 60 seconds

- `Storage\RequestIdService` holds the context; `ResetInterface` + `kernel.reset` tag.
- Entry points reset it: `HTTPRequestListener` (main request only, and only when the context is
  still the previous main request's — early logs keep their ids, 1.3+), `ConsoleSubscriber`,
  `IncomingStampMiddleware` (only for messages with `ReceivedStamp`).
- Exits read it: `HttpClient\HttpClientDecorator` on `http_client.transport` (priority -15),
  Guzzle middleware via the client's `handler` config or, for factory-built clients, a service
  configurator (`GuzzlePass`, Guzzle 7 and 8), `OutgoingStampMiddleware`,
  `RequestIdProcessor`, `TracingIntegration` (W3C → Sentry tags `trace_id`/`span_id`, 1.2+).
- W3C Trace Context (1.1+, `msstc4symfony_tracing.w3c_trace_context`): state lives in
  `RequestIdService` (`W3cTraceContextInterface`); integrations take it as an optional argument,
  null unless the alias is registered (`DependencyInjection\W3cTraceContextWiring`).
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
