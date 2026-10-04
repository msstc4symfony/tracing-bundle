# Известные проблемы и находки

## Типичные ошибки (не повторять)

- Пассы: `getClass() instanceof Interface` на строке класса никогда не истинно — декораторы не
  регистрируются; ссылка на `$id` вместо `.inner` даёт цикл.
- `HttpClientDecorator::withOptions()` обязан возвращать декорированный клиент.
- `HTTPRequestListener` не сбрасывает контекст на подзапросах.
- Тег `messenger.middleware` Symfony не обрабатывает — middleware перечисляют в
  `buses.*.middleware`; без сброса на входе id предыдущего сообщения протекает в следующее.
- Без сброса контекста между запросами (`kernel.reset`) долгоживущие воркеры переносят id.
- Сервисы — в `services.php`, не в YAML: YAML требует `symfony/yaml`, а верификатор стандарта ищет
  `YamlFileLoader` и `ContainerConfigurator::import('*.yaml')` не видит.

## Messenger: пакетные обработчики (BatchHandler) — одна трасса на пачку

`Worker::flush()` и пачка, сработавшая на N-м сообщении, обрабатываются в трассе того
сообщения, на котором сработал сброс пачки. Разделить трассу по сообщениям пачки нельзя:
обработчик видит их одним вызовом.

## Ревью 2026-10-01: первый вариант Incoming-middleware ломал трассы

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

## Эквивалентный мутант `RequestIdGuzzleHandler::decorateHandler()`

`remove(MIDDLEWARE_NAME)` перед `push()` не убить: второй экземпляр middleware видит уже
выставленные заголовки и ничего не добавляет. `remove()` держит стек без дублей, когда один
`HandlerStack`-сервис разделяют несколько клиентов (каждое определение клиента вызывает
`decorateHandler()` на нём).

## prefer-lowest: risky «did not remove its own exception handlers» (2026-10-02 UTC)

Ячейка `--prefer-lowest` (PHP 8.4, Symfony 7.4.*) давала risky в kernel-тестах
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

## sync:// без штампа (ревью 2026-10-02 UTC)

Шина без `OutgoingStampMiddleware` даёт sync-сообщение без штампа; `enter()` делает `reset()`.
`IncomingStampMiddleware` для такой вложенной единицы продолжает трассу внешней
(`continueTrace(outer->traceParent)`, дочерний span); внешний `traceParent` перед снимком
и request id запускаются, чтобы обе стороны делили trace-id и request id/from.

## Логи до `HTTPRequestListener` (2026-10-02 UTC)

Безусловный `reset()` в слушателе выбрасывал бы лениво созданные runtime/request/trace id
записей, сделанных до него (загрузка ядра, request-слушатели с бо́льшим priority). Поэтому
`reset()` — только если с предыдущего главного запроса не было `kernel.reset` (флаг, см.
architecture.md), priority 2048. Ловят
`HTTPRequestListenerTest::testKeepsTheIdsLoggedBeforeTheListener` и
`ContainerCompileTest::testLogsBeforeTheTracingListenerShareTheRequestTrace` (`EarlyLogListener`,
priority 100000).

Что осталось и не исправимо без нарушения BC:
- при входящих `request-id`/`traceparent` записи до слушателя несут сгенерированные request id и
  trace id (заголовки ещё не прочитаны); общий у них с остальным запросом только runtime id.
  Окно — загрузка ядра и request-слушатели с priority > 2048 (например, `TracingRequestListener`
  sentry-symfony, 4097);
- если главный запрос — первый для слушателя или первый после `kernel.reset`, а между ними в
  том же процессе прошла другая единица работы (первый `$kernel->handle()` внутри консольной
  команды или обработчика сообщения), запрос продолжает трассу этой единицы: флаг
  «юнит открыт сбросом» не знает, что хранилище с тех пор сбрасывали. `Kernel` сбрасывает
  сервисы только перед 2-м и следующими `handle()`. Без `kernel.reset` между запросами сброс есть
  всегда;
- `ConsoleSubscriber` по-прежнему всегда делает `reset()` + `generate()` — записи до
  `ConsoleEvents::COMMAND` (priority 100) получают другие id.

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
Отклонено: эквивалентные мутанты priority ±1 в `HTTPRequestListener::getSubscribedEvents()`.

## Повторное ревью Guzzle 8 и `HTTPRequestListener` (2026-10-02 UTC)

Исправлено:
- `GuzzlePass`: ветка `'$config'` убрана — pass стоит на `TYPE_BEFORE_REMOVING`, к этому моменту
  `ResolveNamedArgumentsPass` уже перенёс именованный аргумент в индекс 0. Изолированный тест
  заменён на `CompilerPassesTest::testTracesANamedConfigArgumentOnceTheContainerResolvedIt`
  (полный `compile()`). Формат лога — `UNREACHABLE_CLIENT_LOG`.
- `HTTPRequestListener`: флаг `kernel.reset` вместо сравнения runtime id (см. architecture.md);
  RED: `testDoesNotInheritAUnitThatRanBetweenRequestsWithoutAKernelReset`. Тег `kernel.reset`
  ловит `ContainerCompileTest::testLogsBeforeTheTracingListenerShareTheTraceOfTheNextRequest`.
- «Ровно один middleware»: заголовки этого не видят (второй middleware не перезаписывает их), а
  у `HandlerStack` Guzzle 8 нет `__toString()` — тест считает записи приватного `$stack`
  через reflection (`tracingMiddlewareCount()`).
- Порядок в `ChainedClientConfigurator`: конфигуратор приложения шлёт запрос и видит, что
  `request-id` ещё нет.
- `ChainedClientConfigurator`: `callable(ClientInterface): void` вместо `mixed`.

Отклонено:
- `GuzzleOptions = array<string, mixed>`: `HandlerStack` Guzzle типизирует опции как
  `array<array-key, mixed>`/`array<mixed>`; при `string` PHPStan отвергает передачу
  `middleware()` в `HandlerStack::push()` и `decorateHandler()` в `new Client(['handler' => …])`
  (`argument.type`). Алиас `GuzzleOptions` введён, но с `array-key`.
- PHPStan на Guzzle 7 (`method_exists` / `ClientWithoutConfig`): PHPStan в bundle-standard
  запускается только на `composer-ci.lock` (Guzzle 8); прогон на Guzzle 7 (prefer-lowest) этих
  ошибок не дал.
