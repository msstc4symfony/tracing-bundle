# Тестирование

- `tests/Unit` — по тесту на каждый класс с поведением; HttpClient — `MockHttpClient`,
  Guzzle — настоящий `Client` с `MockHandler`, Messenger — `StackMiddleware`,
  Sentry — `Scope::applyToEvent()` после `SentrySdk::init()->bindClient()`; W3C-теги и
  отсутствие утечки между unit'ами (`reset()`, `snapshot()/restore()`) — `TracingIntegrationTest`,
  проводка в ядре — `W3cTraceContextTest::testSentryEventsAreTaggedWithTheW3cTrace` (алиас
  `TestKernel::SENTRY_INTEGRATION`, только при установленном Sentry).
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
- W3C: векторы спецификации — `tests/Unit/Storage/W3c/` (`TraceParentTest`, `TraceStateTest`);
  `tests/Integration/W3cTraceContextTest` — реальное ядро: вход → `/call`
  (`OutgoingCallController` делает исходящий запрос через `http_client`), вывод request id,
  второй запрос без `traceparent`, `services_resetter`, логи, Messenger через
  `in-memory://?serialize=true` (штамп проходит PhpSerializer) и `TracedMessageHandler`.
  Окружения ядра: `test` (умолчания: W3C вкл., сообщения не штампуются),
  `w3c_messenger` (`messenger: true`), `w3c_off` (`false`). Кеш ядра — по окружению.
- Под coverage `beStrictAboutCoverageMetadata` делает тест risky, если исполнен класс без
  `#[CoversClass]`/`#[UsesClass]` (обычный `phpunit` этого не видит, infection — падает на
  начальном прогоне). Проверка: `XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text=/dev/null`.
