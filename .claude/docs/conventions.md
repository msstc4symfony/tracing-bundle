# Конвенции

- Опциональная библиотека — проверка по типу символа (`interface_exists` / `trait_exists`),
  в `services.php` и в пассах. `class_exists`/`instanceof` на строке класса — ошибка, из-за
  которой до 1.0.0 декораторы не регистрировались.
- Заголовок, выставленный вызывающим, не перезаписывается (HttpClient — и `name => value`,
  и строки `Name: value`; Guzzle — `hasHeader`).
- `request-from` наружу — всегда `getCurrentRequestFrom()` (этот сервис), не полученный.
- Декораторы HttpClient — через `DecoratorTrait`: `withOptions()` возвращает декорированный
  клиент, `reset()` доходит до транспорта.
- `#[Override]` на переопределениях; комментарии — только «почему», по-английски.
