# Архитектура

## Слои (`deptrac.yaml`)

| Слой | Что | Зависит от |
|---|---|---|
| `Storage` | `RequestIdService(Interface)`, `W3cTraceContextInterface`, `W3c\TraceParent`/`TraceState` — контекст трассы | — |
| `EventListener` | вход HTTP/консоли; константы имён заголовков | `Storage` |
| `Integrations` | `HttpClient`, `GuzzleHttp`, `Messenger`, `Monolog`, `Sentry` | `Storage`, `EventListener` |
| `DependencyInjection` | `HttpClientPass`, `GuzzlePass`, `W3cTraceContextWiring` | `Storage`, `Integrations` |
| корень | `TracingBundle` | `DependencyInjection` |

## Жизненный цикл контекста

`reset()` = новый runtime id + забыть request id/from. Вызывается: `kernel.reset`,
главный HTTP-запрос (с 1.3 — условно, см. ниже), консольная команда, перед сообщением,
полученным воркером, и при закрытии unit воркера (`WorkerTraceSubscriber`). Request id
генерируется лениво.

HTTP (`HTTPRequestListener`, `kernel.request` priority 2048 с 1.3, раньше 100): слушатель сам
`ResetInterface` с тегом `kernel.reset` (1.3.1) и держит флаг `$unitOpenedByReset` (true у нового
объекта и после `kernel.reset`, false после главного запроса). `reset()` хранилища — только при
false. При true контекст уже открыт заново (`services_resetter` в `Kernel::boot()` перед 2-м и
следующими `handle()`, свежий процесс FPM) и записи, залогированные до слушателя, сохраняют
runtime/request/trace id. В 1.3.0 вместо флага сравнивался runtime id (`$previousRuntimeId`):
консольная команда или сообщение in-process между запросами тоже меняют runtime id, и без
`kernel.reset` следующий запрос наследовал их ids. Заголовки
`request-id`/`traceparent` перекрывают request id и trace id, runtime id остаётся.

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
  `getReflectionClass($class, false)`, без автозагрузки в фатал), для каждого не-абстрактного
  сервиса с классом `GuzzleHttp\ClientInterface` (1.3+, Guzzle 7 и 8):
  1. без factory и конструктор объявлен в `GuzzleHttp\Client` → в аргумент `0`/`$config`
     (массив) кладётся `handler` = inline-`Definition(HandlerStack)` с factory
     `[RequestIdGuzzleHandler, 'decorateHandler']` и аргументом — прежний `handler` или `null`.
     Configurator приложения не трогается;
  2. иначе, если у класса есть `getConfig()` → configurator `addHandler`, а при уже заданном —
     inline `ChainedClientConfigurator(свой, RequestIdGuzzleHandler)` (`@internal`): сначала свой;
  3. иначе (Guzzle 8, свой `ClientInterface` без `getConfig()`) — `$container->log()`, клиент не
     трассируется.
  `decorateHandler()`: `HandlerStack` → remove+push middleware (идемпотентно, тот же объект),
  `null` → `HandlerStack::create()` + middleware, голый callable → без изменений (как в 1.0–1.2:
  `getConfig('handler')` должен вернуть то, что дали, например `MockHandler` для `append()`).
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
