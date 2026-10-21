# Известные проблемы и находки

## Типичные ошибки (не повторять)

- Пассы: `getClass() instanceof Interface` на строке класса никогда не истинно — декораторы не
  регистрируются; ссылка на `$id` вместо `.inner` даёт цикл.
- `TraceHttpClientDecorator::withOptions()` обязан возвращать декорированный клиент.
- `HttpTraceListener` не сбрасывает контекст на подзапросах.
- Тег `messenger.middleware` Symfony не обрабатывает — middleware перечисляют в
  `buses.*.middleware`; без сброса на входе id предыдущего сообщения протекает в следующее.
- Без сброса контекста между запросами (`kernel.reset`) долгоживущие воркеры переносят id.
- Сервисы — в `services.php`, не в YAML: YAML требует `symfony/yaml`, а верификатор стандарта ищет
  `YamlFileLoader` и `ContainerConfigurator::import('*.yaml')` не видит.

## Messenger: пакетные обработчики (BatchHandler) — одна трасса на пачку

`Worker::flush()` и пачка, сработавшая на N-м сообщении, обрабатываются в трассе того
сообщения, на котором сработал сброс пачки. Разделить трассу по сообщениям пачки нельзя:
обработчик видит их одним вызовом.

## Ревью 2026-10-01 (1.x): первый вариант Incoming-middleware ломал трассы

Сброс в `finally` после каждого полученного сообщения: при `sync://` из HTTP-запроса
затирал контекст запроса (ответ уходил с чужим `request-id`), а сообщения из
`dispatch_after_current_bus` и логи ack воркера оставались без трассы. Сейчас —
счётчик вложенности, снимок/восстановление и закрытие unit в `WorkerTraceSubscriber`; ловит
`tests/Unit/Messenger/MessageBusTraceTest` на настоящей шине.

## Батч-обработчики: `Worker` пропускает `WorkerRunningEvent` после flush

`Worker::flush()` повторно диспатчит отложенный батч (с `ReceivedStamp`) и, если что-то
подтвердил, делает `continue` без `WorkerRunningEvent`. Поэтому unit закрывается ещё и на
`WorkerMessageReceivedEvent` (priority 4096) и `WorkerStoppedEvent`. Непокрытое окно — всё
между таким flush и следующим `WorkerMessageReceivedEvent`, в трассе батча: оба пути
(`flush(false)` при пустом опросе и `flush(30.0)` по таймауту) → `get()` по всем receivers,
`rateLimit()` с его логом и `WorkerRateLimitedEvent`. Messenger не даёт события между ними.
Ловит `tests/Unit/Messenger/WorkerTraceTest` (настоящий `Worker` + `BatchHandlerTrait` +
`MockClock`); проверяется отсутствие утечки, а не точный порядок событий `Worker`.

## Idle-тики воркера

`WorkerRunningEvent(idle)` приходит каждые `sleep` секунд. Сброс на нём только при открытом
unit — иначе `messenger:consume` получал бы новый runtime id каждую секунду. До первого
сообщения idle-тики идут в трассе команды, после — в одной свежей трассе (сброс при закрытии
unit; `kernel.reset` из `ResetServicesListener` всё равно сбрасывает после каждого сообщения).

## Guzzle 8 и клиенты со своим configurator (2026-10-02 UTC)

Guzzle 8.x (проверено на 8.2.0): `getConfig()` убран из `ClientInterface`, но остался в
`GuzzleHttp\Client` (`@final` в phpdoc); `HandlerStack` больше не имеет `__toString()`;
опция запроса `handler` запрещена — стек задаётся только при создании клиента. Отсюда
middleware через `handler` в конфиге конструктора (см. architecture.md). Клиент со своим
configurator тоже трассируется (configurator сохраняется). Не трассируются только Guzzle 8-клиенты без `getConfig()` и без
конструктора `GuzzleHttp\Client` — пасс пишет их id в compiler log.

PHPStan гоняется на Guzzle 8 (CI-профиль, highest): `method_exists($client, 'getConfig')` в
`addHandler()` на Guzzle 7 PHPStan счёл бы всегда истинным. Тесты, которым нужен Guzzle 8
(`ClientWithoutConfig`), пропускаются на 7 (`method_exists(ClientInterface::class, 'getConfig')`).
Проверка тестами — по поведению: `ContainerCompileTest::assertSendsTheTrace()` подменяет
транспорт стека (`setHandler(RecordingGuzzleTransport)`) и смотрит заголовки.

## Эквивалентный мутант `TraceGuzzleMiddleware::decorateHandler()`

`remove(MIDDLEWARE_NAME)` перед `push()` не убить: второй экземпляр middleware видит уже
выставленные заголовки и ничего не добавляет. `remove()` держит стек без дублей, когда один
`HandlerStack`-сервис разделяют несколько клиентов (каждое определение клиента вызывает
`decorateHandler()` на нём).

## prefer-lowest: risky «did not remove its own exception handlers» (2026-10-02 UTC)

Ячейка `--prefer-lowest` (PHP 8.4, Symfony 7.4.*; с v1.3.0 — PHP 8.2, `conflict` по-прежнему нужен) давала risky в kernel-тестах
(`failOnRisky`). Виновник — транзитивный `symfony/error-handler` ниже 7.4.17 (на 8.x — ниже 8.1.5):
`ErrorHandler::register()` при уже чужом error handler оставлял свой exception handler
(исправлено в 7.4.17 / 8.1.5: `restore_exception_handler()` при `!$handlerIsRegistered &&
null === $prev`). Решение — `conflict` `symfony/error-handler: <7.4.17 || >=8.0,<8.1.5`
**только в composer-ci.json**:
коду бандла это не нужно (лишний exception handler безвреден в рантайме), а `require` для
`symfony/*` верификатор ограничивает ровно `^7.4|^8.0` и шаг «Pin Symfony version»
переписал бы его в `7.4.*`; `conflict` он не трогает. Повтор: скопировать дерево в `$TMPDIR`,
убрать roave-bc и deptrac, прибить `symfony/*` к `7.4.*`, `composer update --prefer-lowest
--prefer-stable`, `vendor/bin/phpunit` (2 теста Guzzle 8 пропускаются — внизу Guzzle 7.15.2).

## Гарды опциональных библиотек проверяет CI-job «PHPUnit without optional libraries»

bundle-standard ставит только `composer.json` и гоняет `vendor/bin/phpunit`.
Тест, которому нужен пакет из одного `composer-ci.json` (Guzzle, Messenger, Clock, Sentry,
HttpClient, MonologBundle), пропускается гардом в `setUp()`/начале метода
(`class_exists`/`interface_exists`/`trait_exists` → `markTestSkipped('<pkg> is not installed')`).
Именованный класс в файле теста, который extends/implements/use опционального типа, роняет
загрузку файла фаталом — такие фикстуры живут в отдельных файлах (`tests/Unit/Messenger/Fixture/`).
`TestKernel` подключает MonologBundle, `http_client`, `messenger` и Guzzle-сервисы только при
наличии пакета (`TestKernel::has*()`), поэтому в минимальной установке ядро всё равно
собирается и `testIncomingTraceIsReturnedInTheResponse`/`testKernelResetForgetsTheTrace`
реально проверяют гарды `services.php`. Без MonologBundle ядро ставит `logger` = `NullLogger`:
fallback-логгер FrameworkBundle пишет debug в stderr. Итог 2026-10-02 UTC: минимальная установка —
190 тестов, 90 skipped; полный профиль (Guzzle 8) — 190, 0 skipped; prefer-lowest (Guzzle 7) — 2 skipped.

## Неизвестный класс штампа не декодируется (2026-10-02 UTC)

Проверено на symfony/messenger 8.1: сообщение со штампом неизвестного класса не декодируется —
`Serializer` бросает `MessageDecodingFailedException` (`is_subclass_of` по классу штампа),
`PhpSerializer` подменяет сообщение на `MessageDecodingFailedException`. Поэтому все
консьюмеры должны стоять на бандле с `TraceStamp`, и поля у `TraceStamp` нельзя добавлять без
выкладки консьюмеров первыми: `unserialize` в `readonly`-класс без такого свойства →
`Error: Cannot create dynamic property`.

## sync:// без штампа (ревью 2026-10-02 UTC; в 2.0 — `withChildSpan()` внешнего контекста, тот же runtime id)

Шина без `OutgoingTraceMiddleware` даёт sync-сообщение без штампа; `enter()` делает `reset()`.
`IncomingTraceMiddleware` для такой вложенной единицы продолжает трассу внешней
(`continueTrace(outer->traceParent)`, дочерний span); внешний `traceParent` перед снимком
и request id запускаются, чтобы обе стороны делили trace-id и request id/from.

## Логи до `HttpTraceListener` (2026-10-02 UTC)

Безусловный `reset()` в слушателе выбрасывал бы лениво созданные runtime/request/trace id
записей, сделанных до него (загрузка ядра, request-слушатели с бо́льшим priority). Поэтому
`reset()` — только если с предыдущего главного запроса не было `kernel.reset` (флаг, см.
architecture.md), priority 2048. Ловят
`HttpTraceListenerTest::testKeepsTheIdsLoggedBeforeTheListener` и
`ContainerCompileTest::testLogsBeforeTheTracingListenerShareTheRequestTrace` (`EarlyLogListener`,
priority 100000).

Что осталось и не исправимо без нарушения BC:
- при входящем `traceparent` записи до слушателя несут сгенерированный trace id (заголовки ещё не
  прочитаны); общий у них с остальным запросом только runtime id (`continuing()` его сохраняет).
  Окно — загрузка ядра и request-слушатели с priority > 2048 (например, `TracingRequestListener`
  sentry-symfony, 4097);
- если главный запрос — первый для слушателя или первый после `kernel.reset`, а между ними в
  том же процессе прошла другая единица работы (первый `$kernel->handle()` внутри консольной
  команды или обработчика сообщения), запрос продолжает трассу этой единицы: флаг
  «юнит открыт сбросом» не знает, что хранилище с тех пор сбрасывали. `Kernel` сбрасывает
  сервисы только перед 2-м и следующими `handle()`. Без `kernel.reset` между запросами сброс есть
  всегда;
- `ConsoleTraceSubscriber` всегда делает `reset()` — записи до `ConsoleEvents::COMMAND` (priority 100)
  получают другие id.

## Эквивалентный мутант `TraceParent::randomId()`

`DoWhile` → `while (false)` не убить: повтор нужен только при нулевом id (вероятность 2^-64 /
2^-128). Цикл оставлен — спецификация запрещает нулевые id.

## Решения ревью W3C, отклонённые

- Общий DTO «traceparent + tracestate» для трёх интеграций (CR-008): у каждой свой API
  заголовков (массив / PSR-7 / штамп), условие «не перезаписывать tracestate» остаётся в каждой;
  выигрыш — 3 строки. В интерфейс добавлена оговорка про реализацию.

## Sentry: почему теги, а не контекст `trace` (2026-10-02 UTC)

Проверено на sentry/sentry 4.32: `Scope::applyToEvent()` **всегда** ставит `contexts.trace`
(span → внешний propagation context → `PropagationContext` скоупа) до глобальных processors, так
что «поставить, если нет» невозможно — только перезаписать. Перезапись ломает связь ошибки с
транзакциями Sentry Performance. `Scope::registerExternalPropagationContext()` — один
статический слот (его занимает `OTLPIntegration`), подменяет и исходящие `sentry-trace`/`baggage`
и DSC, и есть не во всех 4.x — отклонено. `traceparent` SDK 4.32 не парсит
(`TraceHeaderParserTrait` — только формат `sentry-trace`; `getW3CTraceparent()` с 4.12 возвращает
`''`), поэтому trace id Sentry и W3C обычно различаются; коррелировать — по тегу `trace_id`.

`setupOnce()` вызывается один раз на процесс (`IntegrationRegistry` — синглтон), processor
находит интеграцию через текущий hub — в тестах каждый `SentrySdk::init()->bindClient()` с новым
экземпляром работает.

## Ревью Guzzle 8 и ранних логов (2026-10-02 UTC)

Самопроверка уровня medium (агент `acc:code-review-coordinator` недоступен в среде — нет Task-инструмента):
сообщение `GuzzlePass` в compiler log уточнено (клиент `GuzzleHttp\Client` с конфигом-параметром
тоже уходит в ветку `getConfig()`), добавлен тест `testConfiguresAClientWhoseConfigIsAParameter`.
Отклонено: эквивалентные мутанты priority ±1 в `HttpTraceListener::getSubscribedEvents()`.

## Повторное ревью Guzzle 8 и `HttpTraceListener` (2026-10-02 UTC)

Исправлено:
- `GuzzlePass`: ветка `'$config'` убрана — pass стоит на `TYPE_BEFORE_REMOVING`, к этому моменту
  `ResolveNamedArgumentsPass` уже перенёс именованный аргумент в индекс 0. Изолированный тест
  заменён на `CompilerPassesTest::testTracesANamedConfigArgumentOnceTheContainerResolvedIt`
  (полный `compile()`). Формат лога — `UNREACHABLE_CLIENT_LOG`.
- `HttpTraceListener`: флаг `kernel.reset` вместо сравнения runtime id (см. architecture.md);
  RED: `testDoesNotInheritAUnitThatRanBetweenRequestsWithoutAKernelReset`. Тег `kernel.reset`
  ловит `ContainerCompileTest::testLogsBeforeTheTracingListenerShareTheTraceOfTheNextRequest`.
- «Ровно один middleware»: заголовки этого не видят (второй middleware не перезаписывает их), а
  у `HandlerStack` Guzzle 8 нет `__toString()` — тест считает записи приватного `$stack`
  через reflection (`tracingMiddlewareCount()`).
- Порядок в `ChainedClientConfigurator`: конфигуратор приложения шлёт запрос и видит, что
  `traceparent` ещё нет (в 1.x проверялся `request-id`).
- `ChainedClientConfigurator`: `callable(ClientInterface): void` вместо `mixed`.

Отклонено:
- `GuzzleOptions = array<string, mixed>`: `HandlerStack` Guzzle типизирует опции как
  `array<array-key, mixed>`/`array<mixed>`; при `string` PHPStan отвергает передачу
  `middleware()` в `HandlerStack::push()` и `decorateHandler()` в `new Client(['handler' => …])`
  (`argument.type`). Алиас `GuzzleOptions` введён, но с `array-key`.
- PHPStan на Guzzle 7 (`method_exists` / `ClientWithoutConfig`): PHPStan в bundle-standard
  запускается только на `composer-ci.lock` (Guzzle 8); прогон на Guzzle 7 (prefer-lowest) этих
  ошибок не дал.

## `parent_span_id` (v1.1.0, 2026-10-13 UTC)

- Исходящий `traceparent` раньше был `child()` на каждый вызов; этот клиентский span id никто не логировал,
  и `parent_span_id` получателя указывал в пустоту. С 1.1.0 исходящий = свой span единицы работы.
- 1.x: `TraceLogProcessor` читал родителя через `snapshot()` (BC интерфейса). В 2.0 — поле
  `TraceContext::$parentSpanId` напрямую, без проверки на каждую запись.
- **Код должен разбираться на PHP 8.2** (с 2026-10-14 UTC, bundle-standard v2.0.0, профиль `php82`): без
  типизированных констант (`const string X`), без `new readonly class` (оба — 8.3) и без `new X()->m()` /
  `new X()(...)` (8.4) — только `(new X())->m()`. PHPStan (`phpVersion.min: 80200`) ловит типизированные
  константы; остальное ловят только `php -l` в job «minimal» (PHP 8.2) и ячейки PHPUnit на 8.2. Шаблоны Rector
  (`withPhpVersion(PHP_82)`) и CS-Fixer (`new_expression_parentheses`) синтаксис обратно не поднимают.
  `#[Override]` (8.3) допустим: на 8.2 атрибут не инстанцируется. `array_any/all/find` (8.4) — только с
  `symfony/polyfill-php84` в `require`. Symfony 8 ставится только на PHP 8.4+.
  В tracing-bundle `array_find` только в тесте → `symfony/polyfill-php84` в `require-dev`.

## 2.0 (B3, 2026-10-20 UTC)

- **HttpClient оставляет только последнюю из повторённых строк `Name: value`.** `HttpClientTrait::normalizeHeaders()`
  для каждой записи списка делает `$normalized[$lcName] = [...]`, так что вторая строка `baggage: …` затирает
  первую. Поэтому член caller дописывается в ту же строку/значение (`, msstc4symfony.caller=…`). Ловит
  `TraceHttpClientDecoratorTest::testAddsTheCallerToABaggageLineTheCallerSet`.
- `rawurlencode()` кодирует `:` → caller уходит как `msstc4symfony.caller=shop%3Aapi`; получатель декодирует.
- Sentry SDK сам кладёт члены `sentry-*` в `baggage` (sentry-symfony — на `http_client`, до транспорта;
  `GuzzleTracingMiddleware` — `withHeader`, заменяя). Поэтому заданный baggage дополняется `except()`-членами;
  в Guzzle middleware Sentry должен выполняться раньше нашего. `ScopingHttpClient` превращает `headers` в строки
  `Name: value` — список значений baggage приходит несколькими строками (ловят `testKeepsEveryBaggageValueOfAScopedClient`).
- Upgrade 1.x → 2.0: штамп переехал — сообщения в очереди не декодируются; дренировать транспорты (CHANGELOG).
- Security-ревью (фон): полный проброс baggage на любые хосты — решение владельца T-D3 (README/SECURITY §2
  описывают риск); инъекция заголовков невозможна — грамматика `Baggage` отсекает CTL/CR/LF/`"`/`,`/`;`/`\`,
  на выходе `rawurlencode` (`BaggageTest::testNeverEmitsAHeaderBreakingCharacter`).
- Роутинг классов Domain: только подкаталоги (`ValueObject`), иначе `verify-standard` v3 и deptrac их не видят.
- move-classes.php: при пересечении префиксной и классовой строки карты побеждает префикс (класс уходит
  под старым именем) — переименовывать вручную `git mv` или класть классовые строки в отдельный прогон.
- Тест-страж классов из `doc/*.yaml` проверяет **файл** по PSR-4, а не `class_exists()`: автозагрузка
  Messenger-middleware без `symfony/messenger` падает фатально в ячейке CI «without optional libraries»
  (красный CI на v2.0.0-rc.1, исправлено b7c5874).

## 2.1 белый список baggage (2026-10-21 UTC)

- Транспорт HttpClient **может** получить относительный URL: `request('GET', '/x', ['base_uri' => …])` на
  `http_client` передаёт `base_uri` в опциях, резолвит его сам транспорт. Хост берётся из `base_uri` (тест
  `testTakesTheHostOfTheBaseUriForARelativeUrl`). Без хоста и при заданном списке baggage не уходит.
- Infection: `make infection` в sandbox падает («JUnit file could not be found» — `/tmp` read-only); запуск вне
  sandbox с `env -u TMPDIR`. Новый `UsesClass` нужен во всех тестах, которые конструируют middleware
  (`ChainedClientConfiguratorTest` стал risky под failOnRisky Infection).
- Эквивалентный мутант: `TrueValue` в `BaggageRecipients` (множество через `isset`).
- Typed class constants (`const string`) — PHP 8.3; пакет `>=8.2`, не использовать.
- Ревью 2.1 (Major): `base_uri` из `withOptions()` терялся — `DecoratorTrait` кладёт его во внутренний клиент, и
  `request('GET', '/x')` приходил без хоста → разрешённый хост молча без baggage. Тест
  `testTakesTheHostOfABaseUriSetWithOptions`.

## 2.1.1: редиректы и парсер URL (2026-10-21 UTC)

- В 2.1.0 разрешённый хост с редиректом наружу уводил baggage (транспорт Symfony повторяет заголовки). Найдено
  фоновым security-review после релиза; исправлено собственным следованием редиректам (architecture.md).
- Guzzle в 2.1.0 был безопасен (middleware внутри `allow_redirects`) — первоначальная оценка «Guzzle тоже течёт»
  была ошибочной; тесты `testDropsItsBaggageWhenARedirectLeavesTheAllowedHosts` и др. закрепляют поведение.
- `MockHttpClient` принимает лишние опции (`prepareRequest(..., true)`), поэтому `auth_ntlm => null` в тестах не
  падает, а на `NativeHttpClient` — `Option "auth_ntlm" is not supported`. Тест проверяет наличие ключа.
- `MockHttpClient` не следует редиректам → в kernel-тестах (`mock_response_factory`) редирект виден только благодаря
  нашему декоратору; `RecordingResponseFactory::$redirects` задаёт ответы 302.
- Ревью 2.1.1 (до релиза): `query` применялся повторно на каждом хопе и уводил ключи на чужой хост (Critical);
  NTLM из `withOptions()` уходил на чужой origin; `max_redirects` из `%env()%` молча становился 20 (теперь
  `resolveValue` + плейсхолдер); обёртка всех ответов в `AsyncResponse` ломала `stream()` по клонам
  `withOptions()`; потоковое тело на 307/308 уходило пустым (curl — даже после PHP-`rewind()`, поэтому замыкание).
  Все с тестами.
- Второе ревью 2.1.1: «свой `stream()`» (async сначала, потом остальные) задерживал обычные ответы до конца
  цепочек редиректов → переход на «всё `AsyncResponse` от одного клиента, опции `withOptions()` у себя». Форма
  с ресурсом бросала «Uploaded stream ended for body part» → перемотка частей.
- NTLM (исправлено в 2.1.2; диагноз 2.1.1 был ошибочным). Причина — не `AsyncResponse`, а `max_redirects: 0`:
  Symfony ставит `CURLOPT_MAXREDIRS=0`, а libcurl (8.9.1) считает повтор запроса для NTLM переходом и не шлёт Type3
  → 401; через `AsyncResponse` это `LogicException` «CurlResponse is already consumed» (`CurlResponse` при 401+NTLM
  не отдаёт FirstChunk). `RetryableHttpClient` и голый curl с `max_redirects ≥ 1` работают. `FOLLOWLOCATION=false` +
  `MAXREDIRS` -1/1 — работает (кандидат на фикс в Symfony `CurlHttpClient`; `extra.curl` эти опции не пускает).
  Проба третьего ревью врала: встроенный `php -S` рвёт соединение после challenge, честного рукопожатия нет —
  для NTLM нужен keep-alive сервер на `stream_socket_server` (Type1 → 401 с type-2, Type3 → 200 на том же
  соединении). Обход 2.1.2: NTLM-запрос в режиме со списком — без baggage бандла, редиректы у транспорта.
- Третье ревью 2.1.1: собственное слияние `query` из `withOptions()` применяло его и к абсолютным URL (утечка ключа на
  чужие хосты, Critical) — транспорт кладёт default query только в относительный URL (`resolveUrl(..., $queryDefaults)`,
  ключи URL важнее, цепочка `withOptions` забывает прежний query). Теперь `ClientOptions::prepare()` делает ровно так.
  Тело-ресурс повторялся один раз (второй 307 → замыкание не перематывается) и с нуля, а не с исходной позиции —
  `RedirectFollower` хранит исходное тело и `ftell`-позиции его потоков.
- Урок: всё, что декоратор делает вместо Symfony (слияние опций, редиректы), сверять с исходником транспорта
  построчно — каждое «упрощение» было утечкой или регрессией.
- `MockHttpClient` отдаёт ответы фабрики в момент `request()`, а следующий хоп редиректа запрашивается позже —
  порядок очереди `$responses` в тестах это учитывает.
- Тест-рекордер `MockHttpClient`: тело-ресурс приходит в callback как есть (читать `stream_get_contents` с текущей
  позиции), тело-замыкание — читать до `''`.
- Эквивалентные мутанты 2.1.1: тайминг `startTime` и ранние `return` в `RedirectFollower::next()` (цепочка всё
  равно кончается), `instanceof`-сужение в `AllowListTraceHttpClientDecorator::streamAll()`,
  `$context->passthru()` и `->cancel()` в `RedirectFollower`, `max(1, $size)`/`(string)` в замыкании повторного
  тела, порядок `$arguments[0] ?? $arguments['$defaultOptions']` в `HttpClientPass`, запасные `0` для `ftell()`/позиции
  в `RedirectFollower`, ранние `return` в `ClientOptions::prepare()` (без default query; строковый query —
  `TypeError` всё равно будет), `&&`→`||` в условии «относительный без нашего base» (`base_uri` остаётся в опциях,
  и внутренний клиент разрешает URL так же)
  (только экономия ресурсов), `return null` в catch и `is_string`-guard в `RequestUrl::resolve()` (без них результат
  тот же `null`; guard нужен PHPStan).
- Четвёртое ревью 2.1.1 (утечек нет): default query терялся, когда `base_uri` только у внутреннего клиента
  (`MockHttpClient` с `https://example.com`) — относительный URL теперь остаётся относительным с влитым default query
  (`mergeQueryString(..., false)`); строковый `query` отдаётся внутреннему клиенту (он бросает `TypeError`, как
  транспорт); `base_uri` массивом частей принимается (`ClientOptions::base()`); объекты в форме (Stringable,
  публичные свойства) повторяются, `StreamableInterface` — нет.
- Сознательное расхождение: собственный default `query` внутреннего клиента (из его конструктора) остаётся после
  нашего `withOptions()` без query; у транспорта `withOptions()` его забывает. Утечки нет (применяется только к его
  `base_uri`), через FrameworkBundle недостижимо (`default_options` без `query`/`base_uri`).
- Сознательное расхождение: относительный URL без path и query (`''`, `'#f'`) с default query при `base_uri` только у
  внутреннего клиента — транспорт берёт query из base_uri и дописывает default (`dir?bq=1&k=v`), декоратор отдаёт
  `?k=v`, и query base_uri теряется. Base внутреннего клиента декоратору не виден; безопасности не касается.
- Финальное ревью 2.1.1: объекты в форме обходятся как в `normalizeBody` (`RedirectFollower::leaves()`: публичные
  свойства рекурсивно, объект без свойств — лист) — иначе поток в свойстве объекта не перематывался («Uploaded stream
  ended for body part "o[f]"»). Строковый default query отдаётся внутреннему клиенту (`TypeError`, как транспорт).
