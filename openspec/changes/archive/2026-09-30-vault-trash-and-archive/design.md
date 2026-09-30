# Design: a trash bin and an archive for vault items

## Context

At development `4c214a9d`:

- `lib/Db/Secret.php` carries `tombstonedAt` and `tombstoneReason` for GDPR detached copies, but no soft-delete or archive state.
- `lib/Service/SecretService.php:931` `delete()` loads the owned row, cascades link shares (`linkShareService->deleteBySecretId`), secret requests, user shares (`shareService->deleteAllForSecret`), group shares, delegations, attachments (`attachmentService->deleteForSecret` and `deleteGrantsForSecretCopy`), versions and rotation flags, then `mapper->delete()` and a `SECRET_DELETED` audit event.
- `lib/Db/SecretMapper.php:115` `findByOwner()`, `:261` `countByOwner()`, `:396` `searchByNameOrUrl()` (used by the extension match at `lib/Controller/ExtensionController.php:180`) and `:425` `findForUnifiedSearch()` (used by `lib/Search/SecretSearchProvider.php:122`) filter only on owner, folder and type.
- `src/views/SecretList.vue` renders the list through the filter menu at `:190-233` and the selection strip at `:294-333`; `src/dialogs/SecretDeleteConfirmDialog.vue:30` and `src/dialogs/BulkDeleteDialog.vue:35` carry the "There is no trash" copy.
- Daily jobs follow `lib/BackgroundJob/PruneSecretVersionsJob.php` (a `TimedJob`, 86400 s) and are registered in `appinfo/info.xml:111-122`.
- Admin settings live in `lib/Service/AdminSettingsService.php` and the sections under `src/components/settings/`.

## Goals / Non-Goals

**Goals**
- A deleted item can be restored whole for a retention period.
- An archived item is out of everyday sight and out of autofill, and comes back in one action.
- Deleting still ends everybody else's access immediately.

**Non-Goals**
- A trash for application-owned secrets or for folders. Deleting a folder keeps its current cascade options; secrets it deletes go to the trash like any other delete.
- Restoring shares. A restored item comes back unshared; the owner shares it again.

## Decisions

**D1. Two nullable timestamps on the secret row.** `trashed_at` and `archived_at` on `keepiq_secrets`. A trashed item is never also archived: trashing clears `archived_at`. Alternative: a separate trash table holding moved rows. Rejected, because the attachment grants, versions and rotation flags all key on the secret id and would have to move too.

**D2. Revoke sharing at trash time, keep everything else until purge.** Trashing runs the share half of today's cascade (link shares, secret requests, user shares, group shares, delegations) and keeps the owner's own data (ciphertext, attachments, versions, rotation flags). Purge runs the rest. Alternative: keep shares alive while trashed so a restore is complete. Rejected: a recipient would keep reading an item its owner deleted, which is the one thing a delete must never leave behind.

**D3. Filter in the mapper, not in the controllers.** `findByOwner()`, `countByOwner()`, `searchByNameOrUrl()` and `findForUnifiedSearch()` get an explicit state argument with default `live` (neither trashed nor archived). The Trash and Archive views pass `trashed` or `archived`. One place decides visibility, so the extension match and unified search inherit it.

**D4. Retention is an admin setting.** `trash_retention_days` in `IAppConfig` through `AdminSettingsService`, default 30, allowed 1 to 365, shown in a new admin section. `PurgeTrashedSecretsJob` runs daily and purges rows whose `trashed_at` is older than the retention.

**D5. Archive keeps shares.** Archiving is the owner's view of their own list. Recipients' copies are their own rows and are not archived for them.

## Security and zero-knowledge

Nothing new is decrypted or stored in plain text: the two columns are timestamps. The trashed row keeps the same ciphertext it had. Access by others ends at trash time (D2). The audit trail gets `secret.trashed`, `secret.restored`, `secret.purged`, `secret.archived` and `secret.unarchived`, carrying identifiers and the item name only, like `secret.deleted` today. The GDPR account deletion (`AccountDeletionService`) purges trashed and archived rows.

## Risks / Trade-offs

- A trashed item still occupies storage for the retention period, including attachment blobs. The admin can shorten the retention.
- Honey credentials (`HoneyTripwireService`) must not become silent when trashed. They stay in place until purge; tripwire reads keep firing.
- The offline cache (`src/offline`) must drop trashed items at the next online unlock; it already rewrites the snapshot from the live list.

## Seed data

Keepiq owns its tables (keepiq ADR-001) and has no OpenRegister register, so there is no register seed. The dev fixture set gains one trashed and one archived secret for the owner `admin`, so the Trash and Archive views and their e2e flows have something to show.

## Migration

One migration after `Version001000Date20260908000000`: add `trashed_at` and `archived_at` (datetime, nullable) to `keepiq_secrets` and an index on (`owner_id`, `trashed_at`). Existing rows are live (both null). `<version>` in `appinfo/info.xml` bumps so the migration runs.
