## ADDED Requirements

### Requirement: Deleting a secret moves it to the trash

The system MUST move a user-owned secret to the trash when its owner deletes it, by setting `trashed_at`, instead of removing the row. At that moment the system MUST revoke every link share, secret request, user share, group share and delegation of the secret. The ciphertext, attachments, version history and rotation flags MUST be kept until the secret is purged. A trashed secret MUST NOT appear in the default vault list, in-app search, Nextcloud unified search or the browser extension's match results.

#### Scenario: A vault owner deletes a shared secret

- **GIVEN** a vault owner with a secret shared with a colleague
- **WHEN** the owner deletes the secret from the secret detail sidebar on the secret list at /secrets
- **THEN** the secret disappears from the owner's vault list and appears in the Trash view
- **AND** the colleague can no longer open their shared copy
- **AND** the audit trail records a `secret.trashed` event

#### Scenario: The delete dialog tells the truth

- **GIVEN** a vault owner who opens the delete dialog for a secret
- **WHEN** the dialog is shown
- **THEN** it says the secret moves to the trash for the retention period and that its shares end now
- **AND** it does not say that there is no trash

### Requirement: Restoring and purging trashed secrets

The system MUST let the owner restore a trashed secret through `POST /api/v1/secrets/{id}/restore`, which clears `trashed_at` and returns the secret with its value, attachments and version history, and without its former shares. The system MUST let the owner delete a trashed secret for good through `DELETE /api/v1/secrets/{id}/purge`. A daily job MUST purge every secret whose `trashed_at` is older than the admin setting `trash_retention_days` (default 30, allowed 1 to 365), running the full delete cascade.

#### Scenario: A vault owner restores a secret from the trash

- **GIVEN** a vault owner with a secret in the trash for 3 days
- **WHEN** the owner opens the Trash view and chooses Restore on that secret
- **THEN** the secret is back in the vault list with the same value, attachments and version history
- **AND** it has no shares

#### Scenario: The retention period ends

- **GIVEN** the retention is 30 days and a secret was trashed 31 days ago
- **WHEN** the daily purge job runs
- **THEN** the secret row, its attachments, versions and rotation flags are deleted
- **AND** the audit trail records a `secret.purged` event

#### Scenario: Someone else tries to restore

- **GIVEN** a trashed secret owned by one user
- **WHEN** another user calls `POST /api/v1/secrets/{id}/restore` for it
- **THEN** the response is 403 or 404 and the secret stays in the trash

### Requirement: Archiving a secret

The system MUST let the owner archive a live secret through `POST /api/v1/secrets/{id}/archive` and bring it back through `POST /api/v1/secrets/{id}/unarchive`. An archived secret MUST NOT appear in the default vault list, in-app search, Nextcloud unified search, the browser extension's match results or the password health report. Archiving MUST keep the secret's shares, and MUST NOT change what recipients see in their own vaults.

#### Scenario: A vault owner archives a login for a retired system

- **GIVEN** a vault owner with a login for a system that was switched off
- **WHEN** the owner chooses Archive in the secret detail sidebar
- **THEN** the login is gone from the vault list and from the extension's suggestions on that site
- **AND** it is listed in the Archive view

#### Scenario: A vault owner unarchives a login

- **GIVEN** an archived login
- **WHEN** the owner chooses Unarchive in the Archive view
- **THEN** the login is back in the vault list, search and the extension's suggestions
