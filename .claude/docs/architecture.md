# Архитектура

## Слои (`deptrac.yaml`, статус-кво этапа A)

| Слой | Что | Зависит от |
|---|---|---|
| `Storage` | `RequestIdService(Interface)`, `W3cTraceContextInterface`, `W3c\TraceParent`/`TraceState` — контекст трассы | — |
| `EventListener` | вход HTTP/консоли; константы имён заголовков | `Storage` |
| `Integrations` | `HttpClient`, `GuzzleHttp`, `Messenger`, `Monolog`, `Sentry` | `Storage`, `EventListener` |
| `DependencyInjection` | `HttpClientPass`, `GuzzlePass`, `W3cTraceContextWiring` | `Storage`, `Integrations` |
| корень | `TracingBundle` | `DependencyInjection` |

## Жизненный цикл контекста

`reset()` = новый runtime id + забыть request id/from. Вызывается: `kernel.reset`,
главный HTTP-запрос, консольная команда, перед сообщением, полученным воркером,
и при закрытии unit воркера (`WorkerTraceSubscriber`). Request id генерируется лениво.

Messenger (`IncomingStampMiddleware`, счётчик вложенности):
- сообщение верхнего уровня воркера (`ReceivedStamp`, вложенность 0) — сброс + трасса
  из штампа; **после обработки не сбрасывается**: лог ack/fail воркера и сообщения,
  отпущенные `dispatch_after_current_bus`, остаются в трассе; unit закрывает `WorkerTraceSubscriber` →
  `IncomingStampMiddleware::closeUnit()` на `WorkerMessageReceivedEvent` (priority 4096),
  `WorkerRunningEvent`, `WorkerStoppedEvent` — только если unit открыт и вложенность 0, так что
  idle-тики не меняют трассу (до первого сообщения — трасса команды, после — одна свежая);
- сообщение, полученное внутри другой единицы работы (`sync://` из HTTP или из другого
  обработчика) — снимок `snapshot()` до, `restore()` после.

## Точки проводки

- `services.php` — явная регистрация; Guzzle/Messenger/Sentry только если библиотека есть.
- `HttpClientPass` декорирует только `http_client.transport`, приоритет -15: снаружи мока
  `mock_response_factory` (-10). metrics-bundle сидит на -20 (ещё снаружи).
- `GuzzlePass` (`TYPE_BEFORE_REMOVING` — после разрешения `parent:`; класс через
  `resolveValue()` + `getReflectionClass($class, false)`, без автозагрузки в фатал) ставит configurator `RequestIdGuzzleHandler::addHandler` каждому сервису с
  классом `GuzzleHttp\ClientInterface`, если configurator ещё не задан; клиент сохраняет тип.
- Messenger-middleware **не** подключаются сами: их перечисляют в `buses.*.middleware`.

## W3C Trace Context (с 1.1.0)

- Конфиг: корень `msstc4symfony_tracing` (`$extensionAlias`; до 1.1 у бандла не было дерева
  конфига, алиас был `tracing`), `w3c_trace_context: true|false|{enabled, messenger}`
  (`canBeDisabled()` → по умолчанию включено; `messenger` по умолчанию `false`).
- Состояние W3C живёт в том же `RequestIdService` (implements `W3cTraceContextInterface`): три
  поля — `traceParent` (спан этой единицы работы: trace-id + **свой** span id в поле
  `parentId` + flags; лениво `TraceParent::start()`), `remoteTraceParent` (как пришёл),
  `traceState`. `resetRequestData()`/`reset()` чистят их, `snapshot()`/`restore()` несут их
  через новые необязательные поля `TraceContext` → sync-сообщения и воркеры не требуют
  отдельной логики.
- Включение = алиас `W3cTraceContextInterface` → `RequestIdService` (`W3cTraceContextWiring`,
  вызывается из `TracingBundle::loadExtension`; корню бандла deptrac запрещает зависеть от
  `Storage`). Интеграции получают `?W3cTraceContextInterface $w3cTraceContext = null`
  автовайрингом: нет алиаса → `null` → W3C пропускается. `HttpClientPass` ставит
  `Reference(..., NULL_ON_INVALID_REFERENCE)`. При `messenger: false` у
  `OutgoingStampMiddleware` аргумент явно `null`.
- `W3cTraceContextPass` (до автовайринга): если класс сервиса `RequestIdServiceInterface`
  реализует и `W3cTraceContextInterface`, алиас W3C → `RequestIdServiceInterface`.
- `TraceState` хранит члены как пришли, но валидирует грамматику, дубликаты ключей, ≤ 32;
  > 512 символов — усечение (сначала члены > 128 с конца, затем с конца). Флаги `TraceParent`
  маскируются до `0x03` (sampled|random).
- Исходящий `traceparent` = `getTraceParent()->child()`: новый span id на каждый запрос /
  сообщение. Логи: `trace_id`, `span_id` (= свой span единицы работы).
- Sentry (с 1.2.0): `TracingIntegration(?W3cTraceContextInterface $w3cTraceContext = null)` в
  глобальном event processor ставит **теги** `trace_id`/`span_id` (те же значения, что в логах),
  читая хранилище в момент захвата события; если на событии уже есть `trace_id` или `span_id`, не
  добавляется ни один (пара не смешивает источники; ревью CR-001). На `Scope` ничего не
  пишется — поэтому утечки между unit'ами нет, сброс — общий `reset()`. Контекст `trace`
  события не трогаем (см. known-issues).
