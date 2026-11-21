---
description: Run Keepiq's PHP and JavaScript unit tests
---

Run the unit tests from the repository root and summarise the failures.

1. PHP: `composer test:unit` (PHPUnit on `tests/Unit`, configured in `phpunit.xml`). Needs `composer install`.
2. JavaScript: `npm run test` (Vitest, configured in `vitest.config.js`). Needs `npm install`.

To run a single PHP test file: `./vendor/bin/phpunit --no-coverage <path>`.
To run a single Vitest file: `npx vitest run <path>`.
