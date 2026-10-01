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

## Гарды опциональных библиотек не проверяются в CI

В отличие от metrics, отдельного job «без опциональных библиотек» нет: тесты
интеграций используют сами библиотеки. Гарды в `services.php` проверены вручную
2026-10-01: установка только `composer.json` (без Guzzle, Messenger, Sentry, HttpClient,
MonologBundle) — ядро собирается, ни одна опциональная интеграция не регистрируется.
