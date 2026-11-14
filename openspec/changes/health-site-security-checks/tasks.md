# Tasks: site checks in the password health report

## 1. Plain http

- [x] 1.1 Add the `insecure-address` flag to `src/health/engine.js` (D1 exclusions) and its category in `HealthReportView.vue` with a Change to https action. Verify: vitest for http, https, localhost and a private address; Playwright check on the fixture login.
  - `src/health/insecureAddress.js` holds the rule (loopback, RFC 1918, link-local and the IPv6 equivalents excluded) and the https rewrite; the engine flags `insecure-address` on the login's plain-text `url` (the only plain-text address a secret has; typed URL fields are encrypted and not in the health rows), counts it as `insecureAddressCount`, and leaves the score alone. `HealthCategory` takes an optional per-finding action; the report's Unencrypted address category saves the https address and analyses again. Vitest: `health-insecure-address.spec.js`, `HealthCategory.action.spec.js`, `HealthReportView.changeToHttps.spec.js`. The Playwright check on the fixture login needs a live instance and is still owed.
- [x] 1.2 Warn before a fill on an `http://` page in the extension. Verify: extension unit test that the fill waits for confirmation on http and not on https.

## 2. Site directory

- [x] 2.1 Confirm the directory endpoints and licence, and record them in this design. Verify: the design names both URLs and the licence, reviewed in the PR.
  - D2a: one-time codes from `api.2fa.directory/v4/totp.json` (MIT), passkeys from `passkeys-api.2fa.directory/v1/supported.json` (CC BY 4.0, the 2FA Directory itself has no passkey data); both need attribution. Confirmed from the projects' own repositories and API pages.
- [x] 2.2 Add `site_directory_enabled` (default off), `RefreshSiteDirectoryJob` (daily, `IClientService`, stored in app data) and `GET /api/v1/site-directory` with an ETag. Verify: PHPUnit with a mocked client for refresh, a failed fetch keeping the old file, and 404 when switched off; hydra route-auth gate.
  - `SiteDirectoryService` (switch read, whole download of both directories with `IClientService`, kept per domain with its documentation link, stored in app data `site-directory/directory.json`; a failed or malformed download keeps the last copy), `RefreshSiteDirectoryJob` (daily, in `info.xml`), `SiteDirectoryController::index()` at `GET /api/v1/site-directory` (`NoAdminRequired`, no parameters; 401 without a user, 404 while off or before the first copy, the copy with a sha256 ETag, 304 on a match). `site_directory_enabled` is a General-area admin key, default off. PHPUnit: `SiteDirectoryServiceTest` (6), `SiteDirectoryControllerTest` (6), `RefreshSiteDirectoryJobTest` (2); admin settings golden updated; openapi.json regenerated.
- [x] 2.3 Add the switch with the privacy and attribution text to the admin settings. Verify: Playwright check of the section.
  - `src/components/settings/SiteDirectorySection.vue` (`#section-site-directory`) in the General admin area after Breach checking: the switch, which hosts are downloaded and that nothing is sent, and the attribution both licences ask for with links. EN + NL strings. Vitest `tests/components/SiteDirectorySection.spec.js` (9). The Playwright check needs a live instance and is not done in this lane.

## 3. Directory categories

- [x] 3.1 Move the registrable-domain helper from `browser-extension/src/lib/match.js` to a module both the web app and the extension import. Verify: the existing extension match tests pass unchanged.
  - The helper lives in `browser-extension/src/vault/match.ts`; its rule moved to `src/shared/registrableDomain.js` (with a `.d.ts`), which imports nothing and takes the public suffix lookup as an argument, because the extension and the web app are separate npm packages and the extension's CI installs only its own. `baseDomain()` now calls it with the extension's `tldts`; the web app gains `tldts` 7.4.15 (the extension's pin, SBOM regenerated). Extension: `match.test.ts` unchanged and passing, whole suite 8760 passed, typecheck and build green. Web: `tests/vitest/registrableDomain.spec.js` (11).
- [x] 3.2 Add the `two-factor-available` and `passkey-available` flags to the engine using the directory and the vault facts of D4, and their categories in the report. Verify: vitest with the directory fixture, a login with and without a seed, and with and without a passkey item.
  - `src/health/siteDirectory.js` (host and registrable domain of a saved address through the shared rule, the directory entry by host then domain, the vault's own authenticator and passkey items by domain). The health store marks each row `hasSeed` / `hasPasskey` (a code or passkeys on the login, or an item for its site), fetches `GET /api/v1/site-directory` whole (404 = off, any other failure = unavailable) and passes it to the engine and worker; the engine adds the two flags with the directory's link for the site, counts them, and leaves the score alone. The report shows both categories with a link action and the attribution, or one line saying why they are unavailable. EN + NL strings. Vitest: `health-site-directory.spec.js` (17, with `tests/fixtures/site-directory.json`), `tests/store/healthSiteDirectory.spec.js` (8), `HealthReportView.siteDirectory.spec.js` (4); the analyse golden gains the two counts, `healthRows.spec.js` the two row fields.

## 4. Docs

- [x] 4.1 Document the three categories and the site directory setting in `docs/password-health.md`. Verify: docs build.
  - The Unencrypted address entry (from 1.1), plus a Site directory section: both categories, how a site matches, the two sources with their licences, why the list is downloaded whole, and how to switch it on. The docs build was not run here: `npm ci` in `docs/` refuses because its `package-lock.json` is out of sync with `package.json` on development itself.

## Acceptance criteria

- Logins saved with a plain http address are listed and can be changed to https in one action; the extension warns before filling on an http page.
- With the site directory on, logins for sites that offer one-time codes or passkeys the user has not set up are listed.
- No login address, flag or count is sent to the server.
