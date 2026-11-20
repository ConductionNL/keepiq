---
description: Run Keepiq's Playwright end-to-end tests against a running Nextcloud
---

The Playwright tests live in `tests/e2e/` and use `playwright.config.ts`. They need a running Nextcloud with Keepiq enabled and have no default URL.

1. Start the development stack if it is not running: `docker compose up -d` (Nextcloud on http://localhost:8080, admin/admin).
2. Install the browser once: `npx playwright install chromium`.
3. Run the tests: `PLAYWRIGHT_BASE_URL=http://localhost:8080 npx playwright test`.
   Pass a spec file path to run one file. Set `NC_ADMIN_USER` and `NC_ADMIN_PASS` if the admin login is not admin/admin.

Report failing tests with their spec file and the first error.
