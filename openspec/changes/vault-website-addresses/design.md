# Design: more website addresses per login, a site preview and a change-password link

## Context

At development `4c214a9d`:

- `lib/Db/Secret.php:87` holds one plain-text `url`; `openspec/specs/secrets/spec.md:62,566` records that the address is stored unencrypted to enable search and unified search.
- `lib/Controller/SecretController.php:175,297` take `url` on create and update; `lib/Service/SecretService.php:239` and `:825` store it.
- `lib/Db/SecretMapper.php:396` `searchByNameOrUrl()` (in-app search and the extension match through `lib/Controller/ExtensionController.php:180`) and `:425` `findForUnifiedSearch()` match `name` or `url` with `iLike`.
- `browser-extension/src/lib/match.js:40` `hostOf()`, `:56` `registrableDomain()`, `:76` `matchScore()` and `:104` `matchSecrets()` score candidates by their single `url`.
- `lib/Service/ShareSyncService.php:317-345` copies only the encrypted blobs onto recipient copies; the copy's `url` is set when the share is created.
- `lib/Controller/DashboardController.php:101-102` passes `favicon_service_url` (template with `{domain}`) to the frontend; `src/utils/favicon.js` builds the icon URL and `src/components/SecretListItem.vue:13,171` shows it. No admin section edits the setting (`src/components/settings/` has none).
- `src/components/HealthCategory.vue` renders findings of the health report per category.

## Goals / Non-Goals

**Goals**
- One login fills on every address it belongs to.
- A site image helps recognise a login, when the organisation accepts that the host leaves the instance.
- One click from a weak password to the page where it is changed.

**Non-Goals**
- Per-address match rules (exact, starts with, regular expression). All addresses use today's registrable-domain match.
- A server-side probe that checks whether a site supports the well-known address. See D3.
- A preview service run by Keepiq itself.

## Decisions

**D1. Extra addresses are a plain-text child table.** `keepiq_secret_urls` with `position` for order, at most 20 per login. They follow the main address's recorded design: plain text so the server can search and match without the master password. Alternative: inside the encrypted additional fields. Rejected: the extension match and unified search run without decrypting, so an encrypted address could never match.

**D2. Share copies carry the list.** When a share, group share or team-folder fan-out creates a copy, and when the owner changes the list, the copies' address rows are replaced with the owner's list in the same transaction as `ShareSyncService` writes the blobs. The recipient does not edit addresses of a copy, the same as the main address today.

**D3. The change-password link opens the well-known address, the site redirects.** The action opens `https://<host>/.well-known/change-password` for the main address's host. A site that supports the convention redirects to its change page; one that does not shows its own not-found page. Alternative: probe from the server, as Nextcloud Passwords and Bitwarden do. Rejected for now: the probe tells a third party which sites the user has logins for at a time the user did not click, and a false positive costs one page view.

**D4. Previews use an admin-set template, off by default.** `site_preview_service_url` with a `{url}` placeholder, next to `favicon_service_url` with `{domain}`, both edited in a new admin section that states that the host or address of every login shown leaves the instance for the configured service. The browser loads the image directly, as it does the favicon today, with `referrerpolicy="no-referrer"`. Alternative: a server proxy that fetches and caches previews. Rejected: it makes the Keepiq server fetch arbitrary sites on behalf of users, which needs its own SSRF hardening.

## Security and zero-knowledge

No secret value is touched. Extra addresses are plain text, stated in the field help text, the same exposure as the main address today. The preview and favicon services are opt-in per instance and default off, and the admin section says what leaves the instance. The change-password link is a plain navigation the user starts.

## Risks / Trade-offs

- An address list widens the extension's candidate set. Candidates are never filled without the user's choice (`browser-extension/src/lib/match.js`, the anti-phishing rule), so a wider list is a longer menu, not a silent fill.
- A preview service is a third party. That is why it is off by default and why the admin section names it.

## Seed data

Keepiq owns its tables (keepiq ADR-001) and has no OpenRegister register. The dev fixture login for `example.com` gets two extra addresses, `login.example.com` and `example.net`, so the extension match and search have something to find.

## Migration

One migration after `Version001000Date20260908000000`: table `keepiq_secret_urls` with an index on (`owner_id`, `url`). `<version>` bumps. The GDPR export and account deletion include the table; backup export writes the list and restore reads it.
