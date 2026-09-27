# Design: favourites, tags and a last-used sort in the vault list

## Context

At development `4c214a9d`:

- `lib/Db/SecretMapper.php:48-53` `SORTABLE_COLUMNS` is `name`, `url`, `created_at`, `updated_at`; `findByOwner()` (`:115`) and `countByOwner()` (`:261`) filter on owner, folder and type.
- `lib/Service/SecretService.php:1000` `list()` passes those filters from `lib/Controller/SecretController.php` `index()` (`:102-115`).
- `lib/Service/SecretService.php:781` `get()` is the single encrypted-blob fetch and already emits `secret.read` (`:790-799`); list and search never call it.
- The browser extension fills from blob rows cached at match time (`browser-extension/src/background/service-worker.js:105-125` `doFill`), so a fill does not call `get()`.
- `src/views/SecretList.vue:190-233` is the filter menu (type filter and `sortOptions` at `:787-792`); `:294-333` is the bulk selection strip; rows render through `src/components/SecretListItem.vue`.
- Share copies are full `Secret` rows per recipient. `lib/Service/ShareSyncService.php:317-345` `applyRecipientBlob()` copies only `key`, `login` and `additionalFields` onto a copy, so per-row fields added here are never overwritten by an owner's edit.
- Folder names are stored unencrypted as organisational metadata (`openspec/specs/secrets/spec.md:44`).

## Goals / Non-Goals

**Goals**
- A person finds the items they use most in one click, labels items across folders, and sorts by what they used last.

**Non-Goals**
- Shared tags that the owner sets for all recipients. Each holder tags their own row.
- Encrypted tags. See D2.
- A recently-used widget on the dashboard; `vault-21` is building that separately.

## Decisions

**D1. Favourite and last-used are columns on the holder's row.** `is_favourite` (boolean, default false) and `last_used_at` (datetime, nullable) on `keepiq_secrets`. Because every recipient has their own row, both are per holder with no extra table.

**D2. Tags are plain text, like folder names.** A tag is organisation, not a secret, and filtering by tag must run in the list query. Encrypting tags would force the whole vault to be decrypted before any filter. Stored in `keepiq_secret_tags` (`secret_id`, `owner_id`, `tag`, unique on `secret_id` + `tag`), normalised to trimmed lowercase, at most 32 characters, at most 20 tags per item. The tags field says in its help text that tags are not encrypted. Alternative: encrypted tags inside `additionalFields`. Rejected for the filter reason above.

**D3. Last used means a value was opened or filled.** `SecretService::get()` sets `last_used_at` next to its existing `secret.read` event. A new route `POST /api/v1/extension/used/{id}` (session or paired app password, owner-scoped) lets the extension report a fill after `doFill()` succeeds. Opening the list does not count. Alternative: derive last used from the audit log (`AuditService::recentlyAccessed()`). Rejected: the audit log is pruned by retention and is not indexed for a sort.

**D4. Filters join the existing query.** `findByOwner()` and `countByOwner()` take `?bool $favourite` and `?string $tag`; the tag filter is an `EXISTS` subquery on `keepiq_secret_tags`. `last_used_at` sorts with nulls last.

## Security and zero-knowledge

No secret value is read or stored. The star and the last-used time are metadata about the holder's own row. Tags are plain text by decision D2 and the UI says so. The used-route is owner-scoped: it accepts only ids of rows the caller holds, and returns 404 otherwise, so it cannot probe other users' secrets.

## Risks / Trade-offs

- A tag can leak meaning ("board-salaries") to a database reader, the same way a folder name or item name can today. The help text is the mitigation.
- Stamping `last_used_at` on every `get()` adds one write per reveal; it is a single-row update on an indexed key.

## Seed data

Keepiq owns its tables (keepiq ADR-001) and has no OpenRegister register. The dev fixture owner `admin` gets two starred secrets, tags `finance` and `on call` on three secrets, and `last_used_at` on four, so the filter and sort have visible results.

## Migration

One migration after `Version001000Date20260908000000`: `is_favourite` (boolean, default false) and `last_used_at` (datetime, nullable) on `keepiq_secrets`; table `keepiq_secret_tags` with an index on (`owner_id`, `tag`). `<version>` bumps. The GDPR export (`docs/gdpr.md`) adds tags and favourites to the metadata package; the account deletion cascade deletes the holder's tag rows.
