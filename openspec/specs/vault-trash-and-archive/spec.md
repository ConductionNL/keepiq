# Vault trash and archive Specification

**Status**: done

**OpenSpec changes:**
- [vault-trash-and-archive](../../changes/archive/2026-09-30-vault-trash-and-archive/) _(archived 2026-09-30)_

## Purpose
A deleted secret goes to a trash bin the owner can restore it from until the retention period ends, and an owner can archive a secret to take it out of everyday sight without deleting it. Parity rows vault-04 and vault-27.

## Requirements

### Requirement: Deleting a secret moves it to the trash

The system MUST move a user-owned secret to the trash when its owner deletes it (`DELETE /api/v1/secrets/{id}`), by setting `trashed_at`, instead of removing the row. The response MUST keep `status: deleted` for existing clients and add `trashed: true`. At that moment the system MUST revoke every link share, secret request, user share, group share and delegation of the secret. The ciphertext, attachments, version history and rotation flags MUST be kept until the secret is purged. A trashed secret MUST NOT appear in the default vault list, in-app search, Nextcloud unified search, the dashboard count, the offline cache or the browser extension's match results. Deleting a folder with its contents keeps its immediate delete; placeholder cleanup keeps its hard delete.

#### Scenario: A vault owner deletes a shared secret

@e2e exclude The Playwright browser service is not available to this lane; covered by PHPUnit tests/Unit/Service/SecretTrashServiceTest.php testTrashRevokesSharingAndKeepsTheSecret, tests/Unit/Controller/SecretTrashControllerTest.php testDeleteTrashes and tests/Unit/Db/SecretStateFilterTest.php testEachStateMeansItsConditions.

- **GIVEN** a vault owner with a secret shared with a colleague
- **WHEN** the owner deletes the secret from the secret detail sidebar on the secret list at /secrets
- **THEN** the secret disappears from the owner's vault list and appears in the Trash view
- **AND** the colleague can no longer open their shared copy
- **AND** the audit trail records a `secret.trashed` event

#### Scenario: The delete dialog tells the truth

@e2e exclude Covered by vitest tests/dialogs/TrashDeleteCopy.spec.js 'says one secret goes to the trash and its shares end now' and 'says a selection goes to the trash'.

- **GIVEN** a vault owner who opens the delete dialog for a secret
- **WHEN** the dialog is shown
- **THEN** it says the secret moves to the trash for the retention period and that its shares end now
- **AND** it does not say that there is no trash

### Requirement: Restoring and purging trashed secrets

The system MUST let the owner restore a trashed secret through `POST /api/v1/secrets/{id}/restore`, which clears `trashed_at` and returns the secret with its value, attachments and version history, and without its former shares. The system MUST let the owner delete a trashed secret for good through `DELETE /api/v1/secrets/{id}/purge`; a secret that is not in the trash MUST be refused with 409. A daily job MUST purge every secret whose `trashed_at` is older than the admin setting `trash_retention_days` (default 30, allowed 1 to 365), running the full delete cascade and recording a system `secret.purged` event with reason `retention`. The Trash view at /trash lists trashed secrets, with Restore and Delete for good on a selection.

#### Scenario: A vault owner restores a secret from the trash

@e2e exclude The Playwright browser service is not available to this lane; covered by PHPUnit tests/Unit/Service/SecretTrashServiceTest.php testRestoreClearsTheTrashMark, vitest tests/dialogs/BulkStateDialog.spec.js 'restores every selected secret' and tests/store/secretTrashState.spec.js.

- **GIVEN** a vault owner with a secret in the trash for 3 days
- **WHEN** the owner opens the Trash view, selects that secret and chooses Restore
- **THEN** the secret is back in the vault list with the same value, attachments and version history
- **AND** it has no shares

#### Scenario: The retention period ends

@e2e exclude A background job; covered by PHPUnit tests/Unit/BackgroundJob/PurgeTrashedSecretsJobTest.php testPurgesPastTheRetention and tests/Unit/Service/SecretServiceTrashStateTest.php testRetentionPurgeIsASystemEvent.

- **GIVEN** the retention is 30 days and a secret was trashed 31 days ago
- **WHEN** the daily purge job runs
- **THEN** the secret row, its attachments, versions and rotation flags are deleted
- **AND** the audit trail records a `secret.purged` event

#### Scenario: Someone else tries to restore

@e2e exclude An API scenario; covered by PHPUnit tests/Unit/Controller/SecretTrashControllerTest.php testAnotherUsersSecretIsForbidden and tests/Unit/Service/SecretTrashServiceTest.php testTrashRefusesAnotherUser.

- **GIVEN** a trashed secret owned by one user
- **WHEN** another user calls `POST /api/v1/secrets/{id}/restore` for it
- **THEN** the response is 403 or 404 and the secret stays in the trash

### Requirement: Archiving a secret

The system MUST let the owner archive a live secret through `POST /api/v1/secrets/{id}/archive` and bring it back through `POST /api/v1/secrets/{id}/unarchive`. An archived secret MUST NOT appear in the default vault list, in-app search, Nextcloud unified search, the browser extension's match results or the password health report. Archiving MUST keep the secret's shares, and MUST NOT change what recipients see in their own vaults. An export carries archived secrets (`state=kept`) and never trashed ones. The Archive view at /archive lists archived secrets.

#### Scenario: A vault owner archives a login for a retired system

@e2e exclude The Playwright browser service is not available to this lane; covered by PHPUnit tests/Unit/Service/SecretTrashServiceTest.php testArchiveAndUnarchiveKeepShares, tests/Unit/Db/SecretStateFilterTest.php and vitest tests/store/secretTrashState.spec.js.

- **GIVEN** a vault owner with a login for a system that was switched off
- **WHEN** the owner chooses Archive in the secret detail sidebar
- **THEN** the login is gone from the vault list and from the extension's suggestions on that site
- **AND** it is listed in the Archive view

#### Scenario: A vault owner unarchives a login

@e2e exclude Covered by PHPUnit tests/Unit/Service/SecretTrashServiceTest.php testArchiveAndUnarchiveKeepShares and vitest tests/vitest/detail-route.spec.js 'opens and closes the sidebar over the Archive view'.

- **GIVEN** an archived login
- **WHEN** the owner chooses Unarchive in the Archive view
- **THEN** the login is back in the vault list, search and the extension's suggestions
