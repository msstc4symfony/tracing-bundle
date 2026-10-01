# Тестирование

- `tests/Unit` — по тесту на каждый класс с поведением; HttpClient — `MockHttpClient`,
  Guzzle — настоящий `Client` с `MockHandler`, Messenger — `StackMiddleware`,
  Sentry — `Scope::applyToEvent()` после `SentrySdk::init()->bindClient()`.
- `tests/Integration/ContainerCompileTest` — ядро Framework + Tracing + установленные опциональные
  пакеты (см. known-issues: job без опциональных библиотек): заголовки
  входа/выхода, исходящий `http_client` (через `mock_response_factory` с записью
  заголовков), `extra` в логах (`monolog` handler `test`), `services_resetter`, Guzzle.
- Каталог кеша ядра — на процесс (`getmypid()`): infection параллелит PHPUnit.
- Моки без ожиданий дают notice — `createStub()`.
- Messenger: `MessageBusTraceTest` (настоящая шина, sync/deferred), `WorkerTraceTest`
  (настоящий `Worker`: батч-flush, остановка, running/idle-тики), `UnitBoundaryTest` (защиты по
  вложенности). Ядро настраивает две шины с нашими middleware — проверка, что подписчик и
  шины делят один экземпляр `IncomingStampMiddleware`.
