---
kind: code
---

# A trash bin and an archive for vault items

## Why

Deleting a secret in Keepiq is final. `SecretService::delete()` removes the row and cascades its link shares, user shares, group shares, delegations, attachments, versions and rotation flags in one go (`lib/Service/SecretService.php:931-983`), and both delete dialogs tell the user so: "There is no trash, this cannot be undone" (`src/dialogs/SecretDeleteConfirmDialog.vue:30`, `src/dialogs/BulkDeleteDialog.vue:35`). One wrong click in a bulk delete loses credentials for good. There is also no way to put an item aside: a login for a system that was switched off either stays in the list, in search and in the extension's candidates, or it is deleted.

The bulk-actions spec already names the trash as a separate change (`openspec/specs/bulk-actions/spec.md:94`, "a trash/undo is out of scope and would be a separate change"). This is that change, together with an archive state.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `vault-04` | Recover a deleted item from a trash bin before it is gone for good. | `no`: no soft-delete column or restore path; the delete dialogs say there is no trash |
| `vault-27` | Archive an item so it leaves search and autofill without being deleted. | `no`: no archive state; an item is either live or deleted |

### Demand

- `vault-27`: feature request, https://community.bitwarden.com/t/archive-items-exclude-from-search-autofill/7191
- `vault-04`: no demand row. Five competitors rate it yes and it is in the core area (vault).

### Competitors rated yes

- `vault-04`, Bitwarden: "bitwarden/server@v2026.9.1 src/Api/Vault/Controllers/CiphersController.cs:1267 PUT ciphers/{id}/restore; src/Admin/Jobs/DeleteCiphersJob.cs:30 purge items deleted more than 30 days ago ... Trash with restore and a 30 day automatic purge."
- `vault-04`, 1Password: "restore an item from Recently Deleted for 30 days after the item was deleted" (https://support.1password.com/archive-delete-items/).
- `vault-04`, Keeper: "view and restore previously deleted records from the Deleted Items section"; admins set retention via enforcement policy (https://docs.keeper.io/user-guides/web-vault#deleted-items).
- `vault-04`, HashiCorp Vault: "ui/lib/kv/addon/components/page/secret/details.hbs:75 Undelete button ... Soft-deleted versions stay recoverable with Undelete until destroyed."
- `vault-04`, Nextcloud Passwords: "src/appinfo/routes.php:42 PATCH /api/1.0/password/restore ... src/vue/Section/Trash.vue:53-63 restorePasswordAction ... CleanDeletedEntitiesHelper.php:158 purge job."
- `vault-27`, Bitwarden: "src/Api/Vault/Controllers/CiphersController.cs:1024 PUT ciphers/{id}/archive, :1041 PUT ciphers/archive, :1241 PUT ciphers/unarchive ... libs/common/src/vault/services/cipher.service.ts:552 autofill candidates exclude isArchived ... archived items drop out of search and autofill."
- `vault-27`, 1Password: "Learn how to archive and delete items to keep your information organized"; archived items are kept out of the everyday item list and suggestions (https://support.1password.com/archive-delete-items/).

## What Changes

- **Trash.** Deleting a user-owned secret moves it to the trash instead of removing the row. A trashed item keeps its ciphertext, attachments and version history, so a restore gives it back whole. Shares, link shares, group shares, delegations and open secret requests are revoked at the moment of trashing, exactly as today, so nobody keeps access to an item its owner threw away.
- **Restore and purge.** The owner restores an item from a Trash view, or deletes it for good there. A daily job purges items that have been in the trash longer than the retention period (admin setting, default 30 days), running today's full delete cascade.
- **Archive.** The owner archives an item. An archived item leaves the default vault list, the in-app search, Nextcloud unified search, the browser extension's candidates and the password health report, and it keeps its shares. The owner finds it under an Archive view and unarchives it.
- **Copy.** The delete dialogs stop saying "There is no trash". They say where the item goes and for how long.
- Machine (application-owned) secrets are out of scope: `deleteByApplication()` stays a hard delete.

## Capabilities

### New Capabilities

- `vault-trash-and-archive`: soft delete with restore and timed purge, and an archive state that hides an item from search, autofill and health without deleting it.

### Modified Capabilities

- None in delta form. The secrets and bulk-actions specs are consumed as they stand; the delete cascade in `secrets` moves from delete time to purge time for everything except sharing, which this change's own requirements state.

## Impact

- **Backend**: two nullable columns on `keepiq_secrets` (`trashed_at`, `archived_at`); `SecretService::delete()` becomes a trash move plus the sharing revocation; new `restore()`, `purge()`, `archive()`, `unarchive()`; filters in `SecretMapper::findByOwner()`, `countByOwner()`, `searchByNameOrUrl()` and `findForUnifiedSearch()`; a new `PurgeTrashedSecretsJob`; routes before the SPA catch-all.
- **Frontend**: Trash and Archive views of the secret list, restore and archive actions in the secret detail sidebar and the bulk strip, new delete dialog copy.
- **Browser extension**: none; the match endpoint stops returning trashed and archived items server-side.
- **Database**: one migration adding two columns and an index on (`owner_id`, `trashed_at`); `<version>` bump.
- **Security**: no new plaintext on the server; revocation of sharing stays immediate. The GDPR account deletion cascade purges trashed and archived items with everything else.
- **Cross-app**: OpenConnector reads application-owned secrets only, which this change does not touch.
