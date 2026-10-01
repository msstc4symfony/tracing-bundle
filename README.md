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
| HTTP | `request-id` / `request-from` request headers | same headers on the response and on every Symfony HttpClient / Guzzle request |
| Messenger | `RequestIdStamp` on consumed messages | `RequestIdStamp` on dispatched messages |
| Logs | — | `runtime_id`, `request_id`, `request_from` in every Monolog record's `extra` |
| Sentry | — | the same keys in every event's `extra` (opt-in) |

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

## Opt-in integrations

**Messenger** — middleware must be listed on the buses ([doc/messenger.yaml](doc/messenger.yaml)).
A message consumed by a worker runs in its sender's trace, which stays active for the
worker's own logs and for messages released by `dispatch_after_current_bus`, and is cleared
before the next message. A message handled synchronously (`sync://`) returns to the caller's
trace afterwards. A batch handler processes the whole batch in one message's trace.

**Sentry** — add the integration ([doc/sentry.yaml](doc/sentry.yaml)).

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
