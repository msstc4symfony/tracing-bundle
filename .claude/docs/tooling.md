# Инструментарий

- `composer.json` — публикуемый; `composer-ci.json` + `composer-ci.lock` — то же плюс
  Guzzle, HttpClient, Messenger, Sentry, MonologBundle и CI-инструменты. Оба ставятся в один
  `vendor/`; основной профиль — CI.
- `make check` запускать с `COMPOSER=composer-ci.json`.
- PHPStan level 10 на `src/` и `tests/` (с 1.3.0), baseline пуст — держать пустым, без
  `@phpstan-ignore`. Анализ — на CI-профиле (Guzzle 8).
- `composer-ci.json` `conflict` на `symfony/error-handler` — ради prefer-lowest, см. known-issues.
- `symfony/service-contracts` (`ResetInterface`) объявлен в `require` обоих манифестов (`^2.5|^3`,
  верификатор v1.8.0 разрешает свой major-диапазон для `*-contracts`).
- Rector `ArrayToFirstClassCallableRector` превращает `->factory([X::class, 'create'])` в
  `X::create(...)`, а `FactoryTrait::factory()` Symfony 6.4 не принимает `Closure` — в
  `TestKernel` фабрики строкой `X::class . '::create'`.
- Rector: пропущены `FromServicePublicToDefaultsPublicRector` и
  `ServiceSettersToSettersAutodiscoveryRector` — переписывают `services.php` в публичное
  автообнаружение и ломают `interface_exists`-гарды.
