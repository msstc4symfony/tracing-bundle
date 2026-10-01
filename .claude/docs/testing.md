# Тестирование

- `tests/Unit` — по тесту на каждый класс с поведением; HttpClient — `MockHttpClient`,
  Guzzle — настоящий `Client` с `MockHandler`, Messenger — `StackMiddleware`,
  Sentry — `Scope::applyToEvent()` после `SentrySdk::init()->bindClient()`.
- `tests/Integration/ContainerCompileTest` — ядро Framework + Monolog + Tracing: заголовки
  входа/выхода, исходящий `http_client` (через `mock_response_factory` с записью
  заголовков), `extra` в логах (`monolog` handler `test`), `services_resetter`, Guzzle.
- Каталог кеша ядра — на процесс (`getmypid()`): infection параллелит PHPUnit.
- Моки без ожиданий дают notice — `createStub()`.
