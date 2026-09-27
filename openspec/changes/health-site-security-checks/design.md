# Design: site checks in the password health report

## Context

At development `4c214a9d`:

- `src/health/engine.js:95-175` builds findings per row with flags `weak`, `reused`, `stale`, `compromised` and `breached`; rows come from `src/store/modules/health.js:159` `loadDecryptedRows()`, which excludes authenticator seeds, and the engine runs in `src/health/worker.js`.
- `src/views/HealthReportView.vue:95-153` renders one `HealthCategory` per flag.
- `openspec/specs/password-health/spec.md:111-117` "No Server-Side Health Knowledge": no endpoint accepts scores, digests, reuse data or verdicts.
- `lib/Controller/BreachProxyController.php:51-57,155` proxies the Have I Been Pwned range API with `IClientService`, gated by `breach_check_enabled` (`lib/Service/AdminSettingsService.php:149,349`), default off, edited in `src/components/settings/BreachCheckSection.vue`.
- Passkeys are items of the `passkey` type holding the credential and its relying party id (`openspec/specs/passkey-item-type/`); authenticator seeds are `totp` items or, after `vault-login-totp-codes`, a `totp` key on a login.
- The extension fills through `browser-extension/src/background/service-worker.js:113-145` `doFill()`.

## Goals / Non-Goals

**Goals**
- Tell the user, per login, where a site offers stronger sign-in than they use, and where the saved address is not encrypted.
- Keep every vault fact in the browser.

**Non-Goals**
- An organisation-wide view. The password-health spec forbids the server knowing any of this (`health-13` is decided no on that record).
- Checking whether a site redirects http to https. A saved `http://` address is flagged as saved.

## Decisions

**D1. The http check is a pure client-side rule.** Any address of a login starting with `http://`, except `localhost`, loopback and private-network hosts, is flagged. The extension warns before filling on a page whose own URL is `http://`.

**D2. The site directory is fetched whole by the server and matched in the browser.** Querying a directory per host would tell its operator which sites the user has. Downloading the whole list does not. The server fetches it, as the breach check reaches HIBP through the instance's own proxy, so the browser talks only to the Keepiq instance: Nextcloud's default content security policy limits an app page's connections to its own origin, and one cached copy serves every user. It stores the list in app data, refreshes it daily, and serves it at `GET /api/v1/site-directory` with a hash so the browser caches it. The first task confirms the directory's endpoints and licence (the 2FA Directory at 2fa.directory publishes TOTP and passkey support) and records them here before any code is written.

**D3. Gate it like breach checking.** `site_directory_enabled`, default off, in the admin section next to breach checking. With it off, the two directory categories show as unavailable and the http category still works.

**D4. "Has a second factor" and "has a passkey" are vault facts.** A login has a second factor when it carries a seed or an authenticator item matches its host; it has a passkey when a passkey item's relying party id matches its registrable domain. Matching reuses the extension's registrable-domain helper, moved to a shared module.

## Security and zero-knowledge

The server sends a public list and receives nothing from the user beyond the authenticated request for that list. Matching, the categories and their counts stay in the browser and are dropped on lock, keeping "No Server-Side Health Knowledge". The download of the list does not depend on what is in any vault.

## Risks / Trade-offs

- A stale or wrong directory entry produces a wrong finding. The finding links to the directory's entry so the user can check it.
- The directory licence may require attribution; the admin section and the report carry it.

## Seed data

Keepiq owns its tables (keepiq ADR-001) and has no OpenRegister register. The dev fixture vault gets one login with an `http://` address, and the test suite ships a small directory fixture with one TOTP site and one passkey site, so all three categories show without a network.

## Migration

None. One app config key with a default, and a file in app data created by the job.
