# CI

`.github/workflows/checks.yml` → `bundle-standard/.github/workflows/php-bundle.yml@v1.0.0`
(PHP 8.4/8.5 × Symfony 7.4/8.x, плюс ячейка `--prefer-lowest` на PHP 8.4 + Symfony 7.4;
PHPStan, CS-Fixer, Rector, deptrac, audit, Infection; Roave BC check включён — дефолт стандарта). Пороги
Infection: `infection-min-msi: 90`, `infection-min-covered-msi: 90` (замер 2026-10-02 UTC: 94.46;
порог = замер − ~4). Infection идёт только на push в `main`. Codecov выключен.
Менять гейт — в `bundle-standard`. Highest-ячейки ставят Guzzle 8, prefer-lowest — Guzzle 7
(`composer-ci.json`: `guzzlehttp/guzzle: ^7.8|^8.0`).
