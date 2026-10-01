# Changelog

## 1.0.0

First release as `msstc4symfony/tracing-bundle` (`Msstc4Symfony\TracingBundle`), MIT.

- Symfony HttpClient tracing now actually works: the shared transport is decorated, so every
  framework client (default and scoped) sends the headers; `withOptions()` keeps tracing.
- Guzzle clients get the tracing middleware through a service configurator and keep their type.
- Messenger: middleware must be listed on the buses (see `doc/messenger.yaml`); consumed
  messages run in the sender's trace, kept for the worker's logs and deferred dispatches and
  cleared on `WorkerRunningEvent`; synchronous (`sync://`) handling restores the caller's trace.
- The context resets on `kernel.reset`, on main HTTP requests and on console commands;
  sub-requests keep the main trace.
- `request from` comes from `APPLICATION_NAME` / `COMPONENT_NAME` (was
  `METRICS_APPLICATION_NAME` / `METRICS_COMPONENT_NAME`).
- PHP >= 8.4, Symfony 6.4 / 7.x / 8.x; no YAML dependency.
