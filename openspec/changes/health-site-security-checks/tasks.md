# Tasks: site checks in the password health report

## 1. Plain http

- [ ] 1.1 Add the `insecure-address` flag to `src/health/engine.js` (D1 exclusions) and its category in `HealthReportView.vue` with a Change to https action. Verify: vitest for http, https, localhost and a private address; Playwright check on the fixture login.
- [ ] 1.2 Warn before a fill on an `http://` page in the extension. Verify: extension unit test that the fill waits for confirmation on http and not on https.

## 2. Site directory

- [ ] 2.1 Confirm the directory endpoints and licence, and record them in this design. Verify: the design names both URLs and the licence, reviewed in the PR.
- [ ] 2.2 Add `site_directory_enabled` (default off), `RefreshSiteDirectoryJob` (daily, `IClientService`, stored in app data) and `GET /api/v1/site-directory` with an ETag. Verify: PHPUnit with a mocked client for refresh, a failed fetch keeping the old file, and 404 when switched off; hydra route-auth gate.
- [ ] 2.3 Add the switch with the privacy and attribution text to the admin settings. Verify: Playwright check of the section.

## 3. Directory categories

- [ ] 3.1 Move the registrable-domain helper from `browser-extension/src/lib/match.js` to a module both the web app and the extension import. Verify: the existing extension match tests pass unchanged.
- [ ] 3.2 Add the `two-factor-available` and `passkey-available` flags to the engine using the directory and the vault facts of D4, and their categories in the report. Verify: vitest with the directory fixture, a login with and without a seed, and with and without a passkey item.

## 4. Docs

- [ ] 4.1 Document the three categories and the site directory setting in `docs/password-health.md`. Verify: docs build.

## Acceptance criteria

- Logins saved with a plain http address are listed and can be changed to https in one action; the extension warns before filling on an http page.
- With the site directory on, logins for sites that offer one-time codes or passkeys the user has not set up are listed.
- No login address, flag or count is sent to the server.
