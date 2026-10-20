# Конвенции

- Опциональная библиотека — проверка по типу символа (`interface_exists` / `trait_exists`),
  в `services.php` и в пассах. `class_exists`/`instanceof` на строке класса — ошибка: декораторы
  не регистрируются (см. known-issues, «Типичные ошибки»).
- Заголовок, выставленный вызывающим, не перезаписывается (HttpClient — и `name => value`,
  и строки `Name: value`; Guzzle — `hasHeader`).
- Caller наружу — всегда `CallerName` этого сервиса (член `msstc4symfony.caller`), полученный пересылается
  только если вызывающий код сам задал baggage с caller.
- Декораторы HttpClient — через `DecoratorTrait`: `withOptions()` возвращает декорированный
  клиент, `reset()` доходит до транспорта.
- `#[Override]` на переопределениях; комментарии — только «почему», по-английски.
- W3C-заголовки: `traceparent` не перезаписывается, если задан вызывающим; `tracestate`
  добавляется только вместе со «своим» `traceparent` и тоже не перезаписывается.
- Невалидный входящий `traceparent` (или повторённый заголовок) игнорируется целиком вместе с
  `tracestate`; парсинг — только `TraceParent::fromHeader()` (возвращает `null`, не бросает).
- Входные заголовки/штампы никогда не бросают: `TraceParent::fromHeader()` → `null`, `TraceState::fromHeaders()` →
  `null`, `Baggage::fromHeaders()` отбрасывает члены. Исключения — только конструкторы/`with()` значений.
- Время жизни контекста — только через `TraceContextHolderInterface` (`current`/`replace`/`reset`); значения
  `TraceContext` неизменяемы, новые поля добавлять во все именованные конструкторы и `with*`.
- Классы Domain — в `Domain/ValueObject/` (правило раскладки v3).
- В минорах 2.x новая зависимость в конструкторе публичного класса — только необязательным последним
  аргументом; новый метод в `TraceContextHolderInterface` — только в мажоре (Roave BC). `@internal` ставится/снимается
  только в мажоре. Спек этапа B: `../docs/superpowers/specs/2026-10-17-stage-b-conventions-design.md`.
