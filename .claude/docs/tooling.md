# Инструментарий

- `composer.json` — публикуемый; `composer-ci.json` + `composer-ci.lock` — то же плюс
  Guzzle, HttpClient, Messenger, Sentry, MonologBundle и CI-инструменты. Оба ставятся в один
  `vendor/`; основной профиль — CI.
- `make check` запускать с `COMPOSER=composer-ci.json`.
- PHPStan level 9 на `src/` и `tests/`, baseline пуст — держать пустым.
- Rector: пропущены `FromServicePublicToDefaultsPublicRector` и
  `ServiceSettersToSettersAutodiscoveryRector` — переписывают `services.php` в публичное
  автообнаружение и ломают `interface_exists`-гарды.
