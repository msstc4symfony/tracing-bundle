# Архитектура

## Слои (`deptrac.yaml`, статус-кво этапа A)

| Слой | Что | Зависит от |
|---|---|---|
| `Storage` | `RequestIdService(Interface)` — контекст трассы | — |
| `EventListener` | вход HTTP/консоли; константы имён заголовков | `Storage` |
| `Integrations` | `HttpClient`, `GuzzleHttp`, `Messenger`, `Monolog`, `Sentry` | `Storage`, `EventListener` |
| `DependencyInjection` | `HttpClientPass`, `GuzzlePass` | `Storage`, `Integrations` |
| корень | `TracingBundle` | `DependencyInjection` |

## Жизненный цикл контекста

`reset()` = новый runtime id + забыть request id/from. Вызывается: `kernel.reset`,
главный HTTP-запрос, консольная команда, вокруг каждого сообщения из транспорта.
Request id генерируется лениво при первом чтении.

## Точки проводки

- `services.php` — явная регистрация; Guzzle/Messenger/Sentry только если библиотека есть.
- `HttpClientPass` декорирует только `http_client.transport`, приоритет -15: снаружи мока
  `mock_response_factory` (-10). metrics-bundle сидит на -20 (ещё снаружи).
- `GuzzlePass` ставит configurator `RequestIdGuzzleHandler::addHandler` каждому сервису с
  классом `GuzzleHttp\ClientInterface`, если configurator ещё не задан; клиент сохраняет тип.
- Messenger-middleware **не** подключаются сами: их перечисляют в `buses.*.middleware`.
