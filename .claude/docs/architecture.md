# Архитектура (2.2.0)

Спек: `../docs/superpowers/specs/2026-10-20-tracing-2-w3c-design.md` (B3). Только W3C Trace Context + W3C
Baggage; `request-id`/`request-from` удалены в 2.0.

## Слои (`deptrac.yaml` = шаблон bundle-standard v3)

| Слой | Классы | Зависит от |
|---|---|---|
| Domain (`Domain/ValueObject/`) | `TraceParent`, `TraceState`, `Baggage`, `TraceContext`, `OutgoingTrace` | — |
| Application | `Port\TraceContextHolderInterface`, `CallerName`, `BaggageRecipients` | Domain |
| Infrastructure | `Context\InMemoryTraceContextHolder`, `HttpClient\TraceHttpClientDecorator`, `HttpClient\AllowListTraceHttpClientDecorator`, `HttpClient\TraceHeaders`, `HttpClient\RequestUrl`, `HttpClient\RedirectFollower`, `HttpClient\ClientOptions`, `HttpClient\PreparedRequest`, `Guzzle\TraceGuzzleMiddleware`, `Guzzle\ChainedClientConfigurator` | Domain, Application |
| Presentation | `EventListener\HttpTraceListener`, `EventListener\ConsoleTraceSubscriber` | Domain, Application |
| Framework | `Messenger\{Incoming,Outgoing}TraceMiddleware`, `Messenger\TraceStamp`, `Messenger\WorkerTraceSubscriber`, `Monolog\TraceLogProcessor`, `Sentry\TracingIntegration` | Domain, Application |
| DependencyInjection | `CompilerPass\HttpClientPass`, `CompilerPass\GuzzlePass`, `CompilerPass\BaggageRecipientsPass` | Application, Infrastructure |
| BundleRoot | `TracingBundle` | DependencyInjection |

Классы Domain лежат в `Domain/ValueObject/`, а не в корне `Domain/`: `SourceLayoutRule` и коллектор deptrac
шаблона принимают только `Domain/(Entity|Document|ValueObject|Enum|Event|Repository|DocumentRepository|Service|Helper|Specification)`.
`WorkerTraceSubscriber` — во Framework, а не в Presentation: он вызывает `IncomingTraceMiddleware::closeUnit()`,
а Presentation → Framework запрещено.

Public API (без `@internal`): Domain целиком, порт, `CallerName`, `BaggageRecipients`, Messenger-middleware и `TraceStamp`,
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
  `GuzzleHttp\Client` (inline `Definition(HandlerStack)` с factory `decorateHandler`; не при опциях дефолтного
  handler без `handler` — `DEFAULT_HANDLER_OPTIONS`, см. known-issues), configurator `addHandler`
  (с `ChainedClientConfigurator`, если у клиента свой), иначе запись в compiler log. PSR-7: при заданном
  baggage недостающие члены → `withAddedHeader()`. `GuzzleTracingMiddleware` Sentry *заменяет* `baggage` — он
  должен выполняться раньше нашего (README).
- Белый список (2.1, `baggage.allowed_hosts`): `BaggageRecipients::allows($host)` решает, трогать ли `baggage`
  вообще. Не в списке → ни нашего baggage, ни caller; заданный вызывающим уходит как есть; `traceparent`/
  `tracestate` — всем. `null` = все хосты, `[]` = никому. Messenger не затронут.
- **Два декоратора** (2.1.1), `HttpClientPass` выбирает по параметру `msstc4symfony_tracing.baggage.allowed_hosts`:
  - `null` → `TraceHttpClientDecorator` (`DecoratorTrait`, как в 2.0: без URL, без редиректов);
  - список → `AllowListTraceHttpClientDecorator` (`AsyncDecoratorTrait`): **каждый** ответ — `AsyncResponse`
    (passthru `RedirectFollower` только при нашем baggage и `max_redirects > 0`, иначе null). `withOptions()` НЕ
    клонирует внутренний клиент (только вызывает его для валидации): опции хранятся в `$defaults` и сливаются в
    запрос `ClientOptions::prepare()` → `PreparedRequest{url, options}`: `mergeDefaultOptions(..., true)` без
    `normalized_headers`; default `query` — только в относительный URL через `resolveUrl($url, $base, $defaultQuery)`
    (как транспорт; ключи URL важнее), затем `query => []`. `ClientOptions::defaults()` для `withOptions()` — как
    у транспорта, забывает прежний default query. Так все ответы от одного `$this->client` и `AsyncResponse::stream()`
    чередует их. Прежние варианты (ответ транспорта как есть / делёж в `stream()`) ломали `stream()` по клонам
    («many clients») или задерживали обычные ответы до конца редирект-цепочек. `max_redirects` — `is_numeric`.
    Аргументы из pass: `$maxRedirects`, `$ntlmDefault` — из `resolveValue()` аргумента 0 или `$defaultOptions`.
    NTLM (2.1.2): `array_key_exists('auth_ntlm', $options) ? !== null : $ntlmDefault` (опции уже слиты с
    `withOptions()`; явный `null` запроса выключает умолчание транспорта) → baggage бандла нет, follower не ставится,
    `max_redirects` не трогается — редиректы ведёт транспорт.
  Общая логика заголовков — `TraceHeaders` (`of`, `with`, `without`, `blocking`).
- Хост — `RequestUrl::resolve($url, $baseUri)` на `HttpClientTrait::parseUrl/resolveUrl` (тот же парсер, что у
  транспорта: `\\` и CR/LF отклоняются, IDN → punycode, `//host` против base_uri; userinfo режется по последнему `@`).
  `base_uri`: из опций запроса, иначе из `withOptions()`. Приватные статики трейта без BC-гарантий —
  `RequestUrlTest` страхует при обновлении http-client.
- Редиректы — `RedirectFollower` (passthru-объект): любой 3xx кроме 304 с первым `Location` (резолвится против
  `getInfo('url')` — фактического URL с query); 301–303 POST и всё
  на 303 → GET (HEAD остаётся), без body/json, `Content-*`/`Transfer-Encoding` блокируются; `query` снимается
  (Location полный — иначе ключи из query уходили на чужой хост, CR-001 ревью); тело на 307/308: исходное тело хранится в `$body`, позиции
  его потоков — `ftell` в конструкторе; на каждом хопе `fseek` к ним: форма — все ресурсы-части seekable, иначе
  конец цепочки; одиночный seekable ресурс → `fseek` и отправка новым замыканием `fread` (curl читает ресурс через `CURLOPT_READDATA` с места, где остановился сам, PHP-`rewind()`
  ему не виден); прочее (closure, iterable, pipe) → цепочка заканчивается 3xx; при origin ≠ первого —
  `Authorization`/`Cookie` блокируются пустым списком (`'Authorization' => []`: `mergeDefaultOptions` не добавит
  умолчание внутреннего клиента), `auth_basic`/`auth_bearer` = `null` (не unset — `$options += $defaults` вернёт),
  NTLM до follower не доходит (см. выше); при возврате на первый origin креды снова идут (как у транспортов). Origin включает userinfo — как у Native
  (`u:p@h` → `h` считается другим origin, креды снимаются). `Host` снимается.
  `redirect_count`, `redirect_time` — через `setInfo`.
- Guzzle: наш middleware в стеке последним = самый внутренний (`HandlerStack::resolve` обходит `array_reverse`),
  `RedirectMiddleware` снаружи и строит следующий запрос из полученного им (без наших заголовков) → каждый хоп
  проходит через нас заново. Утечка возможна только в ручном стеке, где `middleware()` снаружи `allow_redirects`.

## Логи и Sentry

- `TraceLogProcessor` (`monolog.processor`): `runtime_id`, `trace_id`, `span_id`, `parent_span_id`, `caller`.
  Остальной baggage не логируется.
- `TracingIntegration` (opt-in, `sentry.options.integrations`): `extra` `runtime_id`, `caller` (через `+`);
  теги `trace_id`/`span_id`, если на событии нет ни одного из них. Контекст `trace` не трогается.

## Конфиг

Корень `msstc4symfony_tracing`: `application_name`, `component_name`
(`%env(default:msstc4symfony_tracing.unknown:APPLICATION_NAME|COMPONENT_NAME)%`, параметр `…unknown = 'unknown'`
в `services.php`). Ключ `w3c_trace_context` не существует (`InvalidConfigurationException`).
`baggage.allowed_hosts`: `arrayNode` + `defaultNull()` + `stringPrototype()` (null отличим от `[]`; Symfony 7.4+),
параметр `msstc4symfony_tracing.baggage.allowed_hosts` → `BaggageRecipients::$allowedHosts`. Валидация —
`BaggageRecipientsPass` строит `BaggageRecipients` при компиляции и превращает `InvalidArgumentException` в
`InvalidConfigurationException` (BundleRoot по deptrac не видит Application, поэтому не в `configure()`).
Элемент `null` (незаданный `%env(default::X)%`) → `InvalidArgumentException` при создании сервиса, т.е. при первой
сборке HTTP-клиента — ломает весь клиент, не только baggage. Весь список одним `%env()%` Symfony не принимает
(«A dynamic value is not compatible»). Элементы с `%env()%` pass пропускает явно (`resolveEnvPlaceholders($v, null, $usedEnvs)`; формат `true` подставил
бы реальные значения и упал на отсутствующей переменной) — их проверяет `BaggageRecipients` при создании сервиса.
Не-строка (параметр-число) → `InvalidConfigurationException`. `performNoDeepMerging()` — список из позднего файла
заменяет ранний; `fixXmlConfig('allowed_host')`. Явный `~` не поддержан (prototyped array не принимает null).
Запись: имя (`[a-z0-9_-]` метки через точку — `_` ради имён сервисов docker), IPv4, `[IPv6]` (через
`filter_var`), `*.домен` (не совпадает с самим доменом; запрещено над IPv6 и над числовой последней меткой —
`*.0.1` совпадал бы с IPv4); без порта, регистр не важен.

## Не-final классы

Нет: все классы `src/` — `final` или `final readonly`.

## Сэмплинг (2.2.0)

Спек: `../docs/superpowers/specs/2026-10-25-otlp-span-export-design.md` §2. `sampling.ratio` (0..1, по умолчанию 1.0)
→ параметр `msstc4symfony_tracing.sampling.ratio` → сервис `msstc4symfony_tracing.sample_ratio` (`SampleRatio`,
не FQCN-id, чтобы autowiring приложения его не подхватывал) → явный `$sampleRatio` у `InMemoryTraceContextHolder`
(`reset()`, конструктор) и `IncomingTraceMiddleware` (новая трасса сообщения). Решение: младшие 56 бит (14 hex)
trace id ≥ `2^56 - round(ratio * 2^56)` (W3C L2 / OTEP 235). Parent-based: `continuing()` берёт флаг входящего
`traceparent`. `null` ratio во всех точках = «всегда sampled» без создания объекта (иначе strict coverage
metadata делает risky каждый тест, стартующий трассу). Сам бандл спаны не пишет: флаг читают экспортёры
(`tracing-bridge-otlp`) и downstream.

2.3.0: `TraceParent::start()` всегда ставит `FLAG_RANDOM` (флаги новой трассы `03`/`02`). `TraceContext::start($ratio)`
кладёт `TraceState::sampledAt($ratio->threshold())` = `ot=th:<14 hex без хвостовых нулей>` только если ratio < 1 и
трасса sampled. Ratio 1 (в т. ч. дефолт — явную 1 от дефолта не отличить, сервис `SampleRatio` есть всегда) →
`tracestate` нет, провод как в 2.2. `threshold()` = null для ratio 0 (2^56 не влезает в 14 цифр; такая трасса и
не sampled). Наша трасса не имеет входящего tracestate, поэтому `ot` ни с чем не сливается.
