# Архитектура

## Слои (`deptrac.yaml`)

| Слой | Что | Зависит от |
|---|---|---|
| `Storage` | `RequestIdService(Interface)` (request id + W3C), `TraceContext`, `W3c\TraceParent`/`TraceState` — контекст трассы | — |
| `EventListener` | вход HTTP/консоли; константы имён заголовков | `Storage` |
| `Integrations` | `HttpClient`, `GuzzleHttp`, `Messenger`, `Monolog`, `Sentry` | `Storage`, `EventListener` |
| `DependencyInjection` | `HttpClientPass`, `GuzzlePass` | `Storage`, `Integrations` |
| корень | `TracingBundle` | `DependencyInjection` |

## Жизненный цикл контекста

`reset()` = новый runtime id + забыть request id/from. Вызывается: `kernel.reset`,
главный HTTP-запрос (условно, см. ниже), консольная команда, перед сообщением,
полученным воркером, и при закрытии unit воркера (`WorkerTraceSubscriber`). Request id
генерируется лениво.

HTTP (`HTTPRequestListener`, `kernel.request` priority 2048): слушатель сам
`ResetInterface` с тегом `kernel.reset` и держит флаг `$unitOpenedByReset` (true у нового
объекта и после `kernel.reset`, false после главного запроса). `reset()` хранилища — только при
false. При true контекст уже открыт заново (`services_resetter` в `Kernel::boot()` перед 2-м и
следующими `handle()`, свежий процесс FPM) и записи, залогированные до слушателя, сохраняют
runtime/request/trace id. Флаг, а не сравнение runtime id: консольная команда или сообщение in-process между запросами
тоже меняют runtime id, и при сравнении следующий запрос без `kernel.reset` наследовал бы их ids. Заголовки
`request-id`/`traceparent` перекрывают request id и trace id, runtime id остаётся.

Messenger (`IncomingStampMiddleware`, счётчик вложенности):
- сообщение верхнего уровня воркера (`ReceivedStamp`, вложенность 0) — сброс + трасса
  из `TraceStamp` (без штампа — новая трасса, request id = её trace id в UUID-написании); **после обработки не сбрасывается**: лог ack/fail воркера и сообщения,
  отпущенные `dispatch_after_current_bus`, остаются в трассе; unit закрывает `WorkerTraceSubscriber` →
  `IncomingStampMiddleware::closeUnit()` на `WorkerMessageReceivedEvent` (priority 4096),
  `WorkerRunningEvent`, `WorkerStoppedEvent` — только если unit открыт и вложенность 0, так что
  idle-тики не меняют трассу (до первого сообщения — трасса команды, после — одна свежая);
- сообщение, полученное внутри другой единицы работы (`sync://` из HTTP или из другого
  обработчика) — снимок `snapshot()` до, `restore()` после; без штампа — request id/from внешней
  единицы и дочерний span её трассы (`continueTrace(outer->traceParent)`).
- `OutgoingStampMiddleware` ставит один `TraceStamp(requestId, requestFrom, traceParent,
  ?traceState)` на каждое отправляемое сообщение без `ReceivedStamp` и без своего `TraceStamp`.

## Точки проводки

- `services.php` — явная регистрация; Guzzle/Messenger/Sentry только если библиотека есть.
- `HttpClientPass` декорирует только `http_client.transport`, приоритет -15: снаружи мока
  `mock_response_factory` (-10). metrics-bundle сидит на -20 (ещё снаружи).
- `GuzzlePass` (`TYPE_BEFORE_REMOVING` — после разрешения `parent:`; класс через
  `getReflectionClass($class, false)`, без автозагрузки в фатал), для каждого не-абстрактного
  сервиса с классом `GuzzleHttp\ClientInterface` (Guzzle 7 и 8):
  1. без factory и конструктор объявлен в `GuzzleHttp\Client` → в аргумент `0`/`$config`
     (массив) кладётся `handler` = inline-`Definition(HandlerStack)` с factory
     `[RequestIdGuzzleHandler, 'decorateHandler']` и аргументом — прежний `handler` или `null`.
     Configurator приложения не трогается;
  2. иначе, если у класса есть `getConfig()` → configurator `addHandler`, а при уже заданном —
     inline `ChainedClientConfigurator(свой, RequestIdGuzzleHandler)` (`@internal`): сначала свой;
  3. иначе (Guzzle 8, свой `ClientInterface` без `getConfig()`) — `$container->log()`, клиент не
     трассируется.
  `decorateHandler()`: `HandlerStack` → remove+push middleware (идемпотентно, тот же объект),
  `null` → `HandlerStack::create()` + middleware, голый callable → без изменений
  (`getConfig('handler')` должен вернуть то, что дали, например `MockHandler` для `append()`).
- Messenger-middleware **не** подключаются сами: их перечисляют в `buses.*.middleware`.

## Конфиг

Корень `msstc4symfony_tracing` (`$extensionAlias`): `application_name`, `component_name`
(умолчания `%env(default:msstc4symfony_tracing.unknown:APPLICATION_NAME|COMPONENT_NAME)%`;
параметр `msstc4symfony_tracing.unknown` = `'unknown'` задан в `services.php`).
`loadExtension()` кладёт их в параметры `msstc4symfony_tracing.application_name` /
`.component_name`, которые `RequestIdService` читает через `#[Autowire(param:)]`.
Выключателя W3C нет: ключ `w3c_trace_context` не входит в дерево конфигурации, его задание даёт
`InvalidConfigurationException` (окружение `legacy_w3c` в `W3cTraceContextTest`).

## W3C Trace Context (всегда включён)

- Состояние W3C живёт в `RequestIdService` и входит в контракт `RequestIdServiceInterface`
  (`continueTrace`, `getTraceParent`, `getRemoteTraceParent`, `getTraceState`,
  `createOutgoingTraceParent`); отдельного интерфейса, алиаса и пасса нет. Все семь
  интеграций (`HTTPRequestListener`, оба Messenger-middleware, `RequestIdGuzzleHandler`,
  `HttpClientDecorator`, `RequestIdProcessor`, `TracingIntegration`) зависят только от
  `RequestIdServiceInterface`; `HttpClientPass` передаёт обычную ссылку. Три
  поля — `traceParent` (спан этой единицы работы: trace-id + **свой** span id в поле
  `parentId` + flags; лениво `TraceParent::start()`), `remoteTraceParent` (как пришёл),
  `traceState`. `resetRequestData()`/`reset()` чистят их, `snapshot()`/`restore()` несут их
  через обязательные поля `TraceContext` → sync-сообщения и воркеры не требуют отдельной логики.
- `TraceState` хранит члены как пришли, но валидирует грамматику, дубликаты ключей, ≤ 32;
  > 512 символов — усечение (сначала члены > 128 с конца, затем с конца). Флаги `TraceParent`
  маскируются до `0x03` (sampled|random).
- Исходящий `traceparent` = `getTraceParent()->child()`: новый span id на каждый запрос /
  сообщение. Логи: `trace_id`, `span_id` (= свой span единицы работы).
- Sentry: `TracingIntegration(RequestIdServiceInterface)` в
  глобальном event processor ставит **теги** `trace_id`/`span_id` (те же значения, что в логах),
  читая хранилище в момент захвата события; если на событии уже есть `trace_id` или `span_id`, не
  добавляется ни один (пара не смешивает источники; ревью CR-001). На `Scope` ничего не
  пишется — поэтому утечки между unit'ами нет, сброс — общий `reset()`. Контекст `trace`
  события не трогаем (см. known-issues).

## Не-final классы

Нет: все конкретные классы `src/` — `final` или `final readonly`. `ChainedClientConfigurator` —
`final` и `@internal` (не точка расширения).
