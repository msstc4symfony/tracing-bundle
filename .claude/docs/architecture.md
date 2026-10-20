# Архитектура (2.0)

Спек: `../docs/superpowers/specs/2026-10-20-tracing-2-w3c-design.md` (B3). Только W3C Trace Context + W3C
Baggage; `request-id`/`request-from` удалены в 2.0.

## Слои (`deptrac.yaml` = шаблон bundle-standard v3)

| Слой | Классы | Зависит от |
|---|---|---|
| Domain (`Domain/ValueObject/`) | `TraceParent`, `TraceState`, `Baggage`, `TraceContext`, `OutgoingTrace` | — |
| Application | `Port\TraceContextHolderInterface`, `CallerName` | Domain |
| Infrastructure | `Context\InMemoryTraceContextHolder`, `HttpClient\TraceHttpClientDecorator`, `Guzzle\TraceGuzzleMiddleware`, `Guzzle\ChainedClientConfigurator` | Domain, Application |
| Presentation | `EventListener\HttpTraceListener`, `EventListener\ConsoleTraceSubscriber` | Domain, Application |
| Framework | `Messenger\{Incoming,Outgoing}TraceMiddleware`, `Messenger\TraceStamp`, `Messenger\WorkerTraceSubscriber`, `Monolog\TraceLogProcessor`, `Sentry\TracingIntegration` | Domain, Application |
| DependencyInjection | `CompilerPass\HttpClientPass`, `CompilerPass\GuzzlePass` | Application, Infrastructure |
| BundleRoot | `TracingBundle` | DependencyInjection |

Классы Domain лежат в `Domain/ValueObject/`, а не в корне `Domain/`: `SourceLayoutRule` и коллектор deptrac
шаблона принимают только `Domain/(Entity|Document|ValueObject|Enum|Event|Repository|DocumentRepository|Service|Helper|Specification)`.
`WorkerTraceSubscriber` — во Framework, а не в Presentation: он вызывает `IncomingTraceMiddleware::closeUnit()`,
а Presentation → Framework запрещено.

Public API (без `@internal`): Domain целиком, порт, `CallerName`, Messenger-middleware и `TraceStamp`,
`TracingIntegration`, `TraceGuzzleMiddleware`, `TracingBundle`. Список — README «Public API».

## Модель

- `TraceContext` (final readonly, приватный конструктор): `runtimeId` (UUID v4 из `random_bytes`), `traceParent`
  (span этой единицы: trace id + **свой** `spanId` + flags), `remoteTraceParent` (как пришёл), `traceState`,
  `baggage`, `parentSpanId` (span отправителя, `null` у корня).
  - `start()` — всё новое; `continuing($received, $state, $baggage)` — **тот же runtimeId**, trace отправителя,
    `parentSpanId = $received->spanId`; `withBaggage()`; `withChildSpan()` — тот же runtime/trace/baggage, новый
    span, родитель — этот span; `caller()` — член baggage `msstc4symfony.caller`; `outgoing(string $caller)` →
    `OutgoingTrace(traceParent = этот span, traceState, baggage->with(caller))`.
  - Ленивых полей нет — 1.x-грабли «id, созданный во вложенной единице, теряется при restore()» исчезли.
- `TraceContextHolderInterface` (`ResetInterface`): `current()` (никогда не null, держатель стартует контекст в
  конструкторе), `replace()`, `reset()` = `replace(TraceContext::start())`. Вложенная единица:
  `$outer = current(); replace($outer->withChildSpan()); try … finally replace($outer)`.
- `Baggage`: члены `key => {value (decoded), properties (raw)}`; невалидный член отбрасывается поштучно;
  > 180 членов / > 8192 байт — с конца; `with()` заменяет на месте или добавляет **в начало** (усечение с конца
  не теряет caller); повтор ключа — первая позиция, последнее значение; значения `rawurlencode` (`:` → `%3A`).
- `CallerName::$value` = `application_name:component_name` (параметры `msstc4symfony_tracing.*`, умолчание
  `unknown`); Domain его не видит — `outgoing()` принимает строку.

## Жизненный цикл контекста

- `kernel.reset` → `InMemoryTraceContextHolder::reset()` (тег), `HttpTraceListener::reset()` (флаг).
- HTTP (`kernel.request` 2048, главный запрос): флаг `$unitOpenedByReset` — `reset()` держателя только если
  с предыдущего главного запроса не было `kernel.reset`; затем `current()->continuing(...)` при ровно одном
  валидном `traceparent`, иначе `current()->withBaggage(...)` (tracestate отброшен, baggage сохранён). Runtime id
  записей до слушателя сохраняется в обоих случаях.
- Ответ (`kernel.response` 100, главный): `traceresponse = current()->traceParent->toHeader()`, если не задан.
- Console (`ConsoleEvents::COMMAND` 100): `reset()`.
- Messenger `IncomingTraceMiddleware` (счётчик вложенности, `unitOpen`):
  - верхний уровень / вложенное со штампом: `TraceContext::start()->continuing(...)` при валидном traceparent
    штампа, иначе `start()->withBaggage(baggage штампа)`; верхний уровень после обработки не сбрасывается;
  - вложенное без штампа: `$outer->withChildSpan()` (тот же runtime id);
  - вложенное: `finally replace($outer)`;
  - `closeUnit()` (из `WorkerTraceSubscriber`: `WorkerMessageReceivedEvent` 4096, `WorkerRunningEvent`,
    `WorkerStoppedEvent`) — `reset()` только при открытом unit и вложенности 0.
- `OutgoingTraceMiddleware`: один `TraceStamp(traceParent, ?traceState, ?baggage)` на сообщение без
  `ReceivedStamp` и без своего штампа.

## Исходящие HTTP

- `HttpClientPass` декорирует только `http_client.transport`, priority −15 — реестр
  `bundle-standard/doc/decoration-priorities.md` (−10 `mock_response_factory`, −20 metrics). Декораторы
  семейства не зависят от взаимного порядка.
- `traceparent` — если не задан вызывающим; `tracestate` — только вместе с нашим traceparent и если не задан.
- `baggage`: нет у вызывающего → весь накопленный (с caller); есть → значения вызывающего как есть, за ними
  `baggage->except(заданный)` (caller и принятые члены, которых там нет), всё одним ключом `baggage` (строки
  `baggage: …` удаляются — `normalizeHeaders` оставил бы последнюю). Так клиентский baggage Sentry (`sentry-*`,
  ставится на `http_client` до транспорта) не отрезает принятые члены. `Stringable`-значения читаются как строки.
- Guzzle (`GuzzlePass`, `TYPE_BEFORE_REMOVING`): три пути как в 1.x — `handler` в конфиге конструктора
  `GuzzleHttp\Client` (inline `Definition(HandlerStack)` с factory `decorateHandler`), configurator `addHandler`
  (с `ChainedClientConfigurator`, если у клиента свой), иначе запись в compiler log. PSR-7: при заданном
  baggage недостающие члены → `withAddedHeader()`. `GuzzleTracingMiddleware` Sentry *заменяет* `baggage` — он
  должен выполняться раньше нашего (README).

## Логи и Sentry

- `TraceLogProcessor` (`monolog.processor`): `runtime_id`, `trace_id`, `span_id`, `parent_span_id`, `caller`.
  Остальной baggage не логируется.
- `TracingIntegration` (opt-in, `sentry.options.integrations`): `extra` `runtime_id`, `caller` (через `+`);
  теги `trace_id`/`span_id`, если на событии нет ни одного из них. Контекст `trace` не трогается.

## Конфиг

Корень `msstc4symfony_tracing`: `application_name`, `component_name`
(`%env(default:msstc4symfony_tracing.unknown:APPLICATION_NAME|COMPONENT_NAME)%`, параметр `…unknown = 'unknown'`
в `services.php`). Ключ `w3c_trace_context` не существует (`InvalidConfigurationException`).

## Не-final классы

Нет: все классы `src/` — `final` или `final readonly`.
