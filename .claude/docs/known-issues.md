# Известные проблемы и находки

## До 1.0.0 (пакет `hot-ecosystem`, namespace `Hot\TracingBundle`) не работало

- `HttpClientPass`/`GuzzlePass` проверяли `getClass() instanceof Interface` — строка никогда
  не instanceof, декораторы не регистрировались; ссылка на `$id` вместо `.inner` дала бы цикл.
- `HttpClientDecorator::withOptions()` возвращал недекорированный клиент.
- `HTTPRequestListener` сбрасывал контекст на подзапросах.
- Messenger-middleware висели под тегом `messenger.middleware`, который Symfony не
  обрабатывает; id предыдущего сообщения протекал в следующее.
- Нет сброса контекста между запросами в долгоживущих воркерах.
- Конфиг из YAML без `symfony/yaml` (верификатор стандарта ищет `YamlFileLoader` и такой
  `ContainerConfigurator::import('*.yaml')` не видит) — теперь `services.php`.

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

## Guzzle 8: `ClientInterface::getConfig()` удаляется

`RequestIdGuzzleHandler` берёт стек через `getConfig('handler')` — в Guzzle 7 метод
`@deprecated`. На Guzzle 8 подход через configurator придётся заменить (middleware при
создании клиента).

## Guzzle-клиент с уже заданным configurator не трассируется

`GuzzlePass` не заменяет чужой configurator. Такому клиенту middleware надо добавить
вручную: `RequestIdGuzzleHandler::addHandler($client)`.

## Гарды опциональных библиотек проверяет CI-job «PHPUnit without optional libraries»

bundle-standard v1.7.x ставит только `composer.json` и гоняет `vendor/bin/phpunit`.
Тест, которому нужен пакет из одного `composer-ci.json` (Guzzle, Messenger, Clock, Sentry,
HttpClient, MonologBundle), пропускается гардом в `setUp()`/начале метода
(`class_exists`/`interface_exists`/`trait_exists` → `markTestSkipped('<pkg> is not installed')`).
Именованный класс в файле теста, который extends/implements/use опционального типа, роняет
загрузку файла фаталом — такие фикстуры живут в отдельных файлах (`tests/Unit/Messenger/Fixture/`).
`TestKernel` подключает MonologBundle, `http_client`, `messenger` и Guzzle-сервисы только при
наличии пакета (`TestKernel::has*()`), поэтому в минимальной установке ядро всё равно
собирается и `testIncomingTraceIsReturnedInTheResponse`/`testKernelResetForgetsTheTrace`
реально проверяют гарды `services.php`. Без MonologBundle ядро ставит `logger` = `NullLogger`:
fallback-логгер FrameworkBundle пишет debug в stderr. Итог 2026-10-02 UTC (1.2.0): минимальная установка —
162 теста, 68 skipped; полный профиль — 162, 0 skipped.

## W3C: новый класс штампа ломает декодирование у старых консьюмеров (2026-10-02 UTC)

Проверено на symfony/messenger 8.1: сообщение со штампом неизвестного класса не декодируется —
`Serializer` бросает `MessageDecodingFailedException` (`is_subclass_of` по классу штампа),
`PhpSerializer` подменяет сообщение на `MessageDecodingFailedException` (в 6.4/7.x — исключение).
Добавить поля в `RequestIdStamp` тоже нельзя: `unserialize` в `readonly`-класс без такого
свойства → `Error: Cannot create dynamic property`. Поэтому `TraceContextStamp` отдельный и
ставится только при `w3c_trace_context.messenger: true` (включать, когда все консьюмеры ≥ 1.1);
читается всегда, когда W3C включён.

## W3C при подмене `RequestIdServiceInterface`

`W3cTraceContextPass` переводит алиас `W3cTraceContextInterface` на `RequestIdServiceInterface`,
если класс приложенческого хранилища реализует оба интерфейса. Иначе W3C-состояние остаётся в
`RequestIdService` (другой объект): слушатель его не сбрасывает (только `kernel.reset`), а
`snapshot()/restore()` его не несут — описано в README.

## sync:// без штампа (ревью 2026-10-02 UTC)

При `messenger: false` sync-сообщение раньше получало новую случайную W3C-трассу: `enter()`
делает `reset()`, а штампа нет. Теперь `IncomingStampMiddleware` для вложенной единицы без
валидного штампа продолжает трассу внешней (`continueTrace(outer->traceParent)`, дочерний
span); внешний `traceParent` перед снимком запускается, чтобы обе стороны делили trace-id.

## Логи до `HTTPRequestListener` (priority 100)

`RequestIdProcessor` лениво запускает W3C-трассу (`getTraceParent()`), как и request id.
Записи до слушателя получат trace_id, который слушатель затем сбросит — как и request_id
с 1.0.0.

## Эквивалентный мутант `TraceParent::randomId()`

`DoWhile` → `while (false)` не убить: повтор нужен только при нулевом id (вероятность 2^-64 /
2^-128). Цикл оставлен — спецификация запрещает нулевые id.

## Решения по ревью 1.1.0, отклонённые

- Общий DTO «traceparent + tracestate» для трёх интеграций (CR-008): у каждой свой API
  заголовков (массив / PSR-7 / штамп), условие «не перезаписывать tracestate» остаётся в каждой;
  выигрыш — 3 строки. В интерфейс добавлена оговорка про реализацию.
- Config-объект вместо `$config['w3c_trace_context']` (CR-013): одно место, три строки.

## Sentry: почему теги, а не контекст `trace` (1.2.0, 2026-10-02 UTC)

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
