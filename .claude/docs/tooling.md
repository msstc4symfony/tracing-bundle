# Инструментарий

- `composer.json` — публикуемый; `composer-ci.json` + `composer-ci.lock` — то же плюс
  Guzzle, HttpClient, Messenger, Sentry, MonologBundle и CI-инструменты. Оба ставятся в один
  `vendor/`; основной профиль — CI.
- `make check` запускать с `COMPOSER=composer-ci.json`.
- PHPStan level 10 на `src/` и `tests/`, baseline пуст — держать пустым, без
  `@phpstan-ignore`. Анализ — на CI-профиле (Guzzle 8).
- `composer-ci.json` `conflict` на `symfony/error-handler` — ради prefer-lowest, см. known-issues.
- `symfony/service-contracts` (`ResetInterface`) объявлен в `require` обоих манифестов (`^3`, как
  в profiling; верификатор разрешает свой major-диапазон для `*-contracts`). 2.x несовместим с
  Symfony 7.4 (DI 7.4 требует `service-contracts ^3.6`).
- Rector: пропущены `FromServicePublicToDefaultsPublicRector` и
  `ServiceSettersToSettersAutodiscoveryRector` — переписывают `services.php` в публичное
  автообнаружение и ломают `interface_exists`-гарды.
