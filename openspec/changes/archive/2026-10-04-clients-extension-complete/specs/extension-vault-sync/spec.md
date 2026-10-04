## ADDED Requirements

### Requirement: Keep a snapshot of the vault
The extension MUST keep a snapshot of each account's vault in persistent extension storage: the active suite, every secret row as the server stores it (ciphertext and plaintext metadata), the folders and the types, and when it was synced. The snapshot MUST be replaced in one write, MUST be discarded when the account is removed, and MUST hold nothing decrypted. It comes from the offline manifest, or page by page when an administrator switched offline caching off.

#### Scenario: One write per sync
@e2e exclude Browser-extension worker. Covered by tests/extension/vaultSync.spec.js ("stores the snapshot in one write").
- **GIVEN** an unlocked account
- **WHEN** a sync completes
- **THEN** the snapshot is stored with one write, with its time and change check

### Requirement: Sync when it matters and cheaply
The extension MUST sync an account when it unlocks, when the popup opens with a snapshot older than 15 minutes, every 15 minutes while unlocked, after every change made in the extension and on "Sync now", and never while locked. A sync that is not forced MUST first ask only for the most recently updated secret and the total, and stop there when nothing changed. At most one sync per account MUST run at a time.

#### Scenario: Nothing changed
@e2e exclude Browser-extension worker. Covered by tests/extension/vaultSync.spec.js ("costs one cheap request when nothing changed").
- **GIVEN** a fresh snapshot and an unchanged vault
- **WHEN** a scheduled sync runs
- **THEN** only the latest-secret request is made

#### Scenario: Unlock and lock
@e2e exclude Browser-extension worker. Covered by tests/extension/vaultSync.spec.js ("syncs on unlock, schedules a sync while unlocked and stops on lock").
- **GIVEN** a paired account
- **WHEN** it unlocks and then locks
- **THEN** a sync runs and a 15-minute alarm is set, and the alarm is cleared on lock

### Requirement: Work offline from the snapshot
When the server cannot be reached the extension MUST keep serving the snapshot: the Vault tab lists and opens items from it, autofill offers logins from it, and the tab shows "Last synced <time>" with an offline note. Changes MUST NOT be queued: while offline the controls that change the vault are disabled with "You are offline. Changes need a connection to Keepiq."

#### Scenario: Offline
@e2e exclude Browser-extension worker and popup. Covered by tests/extension/vaultSync.spec.js ("offers logins from the snapshot while offline, and the Vault tab reads it").
- **GIVEN** a synced account and no connection to the server
- **WHEN** the user opens a login page and the Vault tab
- **THEN** the saved login is offered, the vault is listed, and New item is disabled with the offline note

### Requirement: A changed suite discards the snapshot
When the synced active suite differs from the one the vault is unlocked with, the extension MUST discard the snapshot and lock the account, so a fresh unlock and sync come first. An authentication failure MUST lock the account; a key-migration lock MUST keep the snapshot and try again later.

#### Scenario: Suite changed
@e2e exclude Browser-extension worker. Covered by tests/extension/vaultSync.spec.js ("discards the snapshot and locks when the suite changed").
- **GIVEN** a snapshot made with one suite
- **WHEN** a sync finds another active suite
- **THEN** the snapshot is gone and the account is locked
