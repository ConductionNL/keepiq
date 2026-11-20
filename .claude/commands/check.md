---
description: Run Keepiq's PHP and frontend quality checks
---

Run the quality suite from the repository root and report each failure with its file and line.

1. PHP (needs `composer install`):
   - `composer check:strict` runs PHP lint, PHPCS, PHPMD, Psalm, PHPStan and PHPUnit.
   - For a faster pass, `composer check` runs lint, PHPCS, Psalm and the unit tests.
2. Frontend (needs `npm install`):
   - `npm run lint`
   - `npm run stylelint`
   - `npm run format`
   - `npm run check:manifest`
3. Translations:
   - `npm run test:l10n`
   - `npm run check:l10n-js`

Fix style issues with `composer cs:fix`, `npm run lint-fix` or `npm run stylelint-fix` only when asked.
