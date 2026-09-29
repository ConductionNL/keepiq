# Tasks: a trash bin and an archive for vault items

## 1. Data and service

- [ ] 1.1 Add the migration with `trashed_at`, `archived_at` and the (`owner_id`, `trashed_at`) index; add the fields to `lib/Db/Secret.php` and its `jsonSerialize()`; bump `<version>`. Verify: PHPUnit on the entity serialisation, and `occ migrations:status keepiq` on a dev instance.
- [ ] 1.2 Split `SecretService::delete()` into `trash()` (sharing cascade plus `trashed_at`) and `purge()` (the remaining cascade plus row delete); keep `delete()` as the trash path for the existing callers. Verify: PHPUnit that trash revokes link, user and group shares and delegations and keeps attachments and versions, and that purge removes them.
- [ ] 1.3 Add `restore()`, `archive()` and `unarchive()` with ownership checks through `loadOwned()` and the five audit event types in `AuditEventTypes`. Verify: PHPUnit for each, including a 403 for a non-owner.
- [ ] 1.4 Add the state argument to `SecretMapper::findByOwner()`, `countByOwner()`, `searchByNameOrUrl()` and `findForUnifiedSearch()` with default live. Verify: PHPUnit that trashed and archived rows are absent from the default list, the unified search provider and the extension match.

## 2. API and jobs

- [ ] 2.1 Add routes `POST /api/v1/secrets/{id}/restore`, `DELETE /api/v1/secrets/{id}/purge`, `POST /api/v1/secrets/{id}/archive`, `POST /api/v1/secrets/{id}/unarchive` and a `state` query parameter (`live`, `trashed`, `archived`) on `GET /api/v1/secrets`, before the SPA catch-all. Verify: hydra route-auth, route-reachability and no-admin-idor gates, and a Newman request per route.
- [ ] 2.2 Add `PurgeTrashedSecretsJob` (daily) and register it in `appinfo/info.xml`; add `trash_retention_days` (default 30, 1 to 365) to `AdminSettingsService`. Verify: PHPUnit with a clock that an item past the retention is purged and one inside it is kept.

## 3. Frontend

- [ ] 3.1 Add Trash and Archive entries to the vault navigation that open the secret list with `state=trashed` or `state=archived`, with Restore and Delete permanently actions on trashed rows and Unarchive on archived rows. Verify: vitest on the store actions and a Playwright flow delete, open Trash, restore.
- [ ] 3.2 Add Archive to the detail sidebar menu and to the bulk selection strip; bulk delete moves to the trash. Verify: Playwright flow archive, item gone from list and search, unarchive.
- [ ] 3.3 Rewrite the copy in `SecretDeleteConfirmDialog.vue` and `BulkDeleteDialog.vue` to say the item goes to the trash for the retention period and its shares end now; add the retention field as a new admin settings section. Verify: `npm run lint`, l10n check, and a Playwright check of the new dialog text.

## 4. Docs

- [ ] 4.1 Document trash, restore, retention and archive in `docs/` and update the bulk-actions note that says there is no trash. Verify: docs build.

## Acceptance criteria

- A deleted secret appears in Trash and can be restored with its value, attachments and version history.
- Nobody but the owner can read a trashed secret from the moment it is trashed.
- A trashed secret is purged after the retention period with the full delete cascade.
- An archived secret is absent from the vault list, in-app search, unified search, the extension candidates and the health report, keeps its shares, and returns with Unarchive.
