# Тестирование

- `tests/Unit` — по тесту на каждый класс с поведением; HttpClient — `MockHttpClient`,
  Guzzle — настоящий `Client` с `MockHandler`, Messenger — `StackMiddleware`,
  Sentry — `Scope::applyToEvent()` после `SentrySdk::init()->bindClient()`; W3C-теги и
  отсутствие утечки между unit'ами (`reset()`, `replace()` внешнего контекста) — `TracingIntegrationTest`,
  проводка в ядре — `W3cTraceContextTest::testSentryEventsAreTaggedWithTheW3cTrace` (алиас
  `TestKernel::SENTRY_INTEGRATION`, только при установленном Sentry).
- `tests/Integration/ContainerCompileTest` — ядро Framework + Tracing + установленные опциональные
  пакеты (см. known-issues: job без опциональных библиотек): заголовки
  входа/выхода, исходящий `http_client` (через `mock_response_factory` с записью
  заголовков), `extra` в логах (`monolog` handler `test`), `services_resetter`, Guzzle
  (клиенты: простой, `parent:`, инлайновый, со своим configurator, со своим `HandlerStack` в
  конфиге, из factory, из factory + configurator; проверка — реальный запрос через
  `RecordingGuzzleTransport`), ранние логи (`EarlyLogListener`).
- Каталог кеша ядра — на процесс (`getmypid()`): infection параллелит PHPUnit.
- Моки без ожиданий дают notice — `createStub()`.
- Messenger: `TraceMiddlewareTest` (штампы, вложенность, невалидный штамп), `MessageBusTraceTest` (настоящая шина, sync/deferred), `WorkerTraceTest`
  (настоящий `Worker`, метка трассы — член baggage `label`: батч-flush, остановка, running/idle-тики), `UnitBoundaryTest` (защиты по
  вложенности). Ядро настраивает две шины с нашими middleware — проверка, что подписчик и
  шины делят один экземпляр `IncomingTraceMiddleware`.
- W3C: векторы спецификации — `tests/Unit/Domain/ValueObject/` (`TraceParentTest`, `TraceStateTest`, `BaggageTest`,
  `TraceContextTest`);
  `tests/Integration/W3cTraceContextTest` — реальное ядро: вход → `/call`
  (`OutgoingCallController` делает исходящий запрос через `http_client`): traceparent/tracestate/baggage
  и `traceresponse`, caller из конфига (`named`) и `unknown:unknown`, второй запрос без `traceparent`, `services_resetter`, логи, Messenger через
  `in-memory://?serialize=true` (штамп проходит PhpSerializer) и `TracedMessageHandler`.
  Окружения ядра: `test` (без конфига бандла), `named` (`application_name: shop`,
  `component_name: api`), `legacy_w3c` (`w3c_trace_context: false` → ожидается
  `InvalidConfigurationException`). Кеш ядра — по окружению.
- Под coverage `beStrictAboutCoverageMetadata` делает тест risky, если исполнен класс без
  `#[CoversClass]`/`#[UsesClass]` (обычный `phpunit` этого не видит, infection — падает на
  начальном прогоне). Проверка: `XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text=/dev/null`.
- Стражи: `tests/Unit/SourceTest` (в `src/` нет `request-id`/`request_from`), `tests/Unit/Resources/ServicesConfigTest`
  (классы из `doc/*.yaml` существуют).
- Тесты каналов строят контекст через `new InMemoryTraceContextHolder()` и
  `$holder->replace($holder->current()->continuing(...))`; известный trace в kernel-тестах — `knownTrace()`.
- `tests/Integration/CurlNtlmTest.php` — настоящий curl против `Fixtures/ntlm-server.php` (keep-alive NTLM-мок на
  `stream_socket_server`, порт 0, печатает порт в stdout; `proc_open`/`proc_terminate`). `php -S` для NTLM не годится:
  рвёт соединение после challenge. Проверяет, что NTLM-запрос на хост из списка завершает рукопожатие без baggage.
