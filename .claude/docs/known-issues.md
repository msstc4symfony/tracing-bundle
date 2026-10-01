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

## Guzzle-клиент с уже заданным configurator не трассируется

`GuzzlePass` не заменяет чужой configurator. Такому клиенту middleware надо добавить
вручную: `RequestIdGuzzleHandler::addHandler($client)`.

## Гарды опциональных библиотек не проверяются в CI

В отличие от metrics, отдельного job «без опциональных библиотек» нет: тесты
интеграций используют сами библиотеки. Гарды в `services.php` проверены вручную
2026-10-01: установка только `composer.json` (без Guzzle, Messenger, Sentry, HttpClient,
MonologBundle) — ядро собирается, ни одна опциональная интеграция не регистрируется.
