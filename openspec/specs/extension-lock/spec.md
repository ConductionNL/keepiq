# extension-lock Specification

## Purpose
What locking does in the browser extension: the popup forgets the vault, a new master password locks, and Lock and Disconnect behave as the user expects.

## Requirements

### Requirement: The popup forgets the vault when it locks
When the worker locks the account on screen, for any reason (idle, system lock, a sync that finds a new suite or master password, a revoked app password), it MUST tell an open popup. The popup MUST at once drop the open item, the form, the vault list, the suggestions, the one-time code and the generated value, and show the lock screen.

#### Scenario: Locked while an item is open
@e2e exclude Browser-extension worker and popup. Covered by tests/extension/lock.spec.js ("drops the open item from the popup the moment the worker locks").
- **GIVEN** the popup with an item open in the Vault tab
- **WHEN** the worker locks that account
- **THEN** the item's values and the list are gone from the popup and the lock screen shows

### Requirement: A changed master password locks the extension
The extension MUST remember the unlock-key epoch of the suite it was unlocked with. When a sync finds the same suite with another epoch, the master password changed: the extension MUST drop the snapshot, lock the account and ask for the new master password.

#### Scenario: The master password changed elsewhere
@e2e exclude Browser-extension worker. Covered by tests/extension/lock.spec.js ("locks and drops the snapshot when the master password changed"), ("keeps going when the epoch is unchanged") and ("locks the extension when the server reports a new unlock-key epoch").
- **GIVEN** an account unlocked at epoch 1
- **WHEN** a sync finds epoch 2
- **THEN** the snapshot is gone, the account is locked and the sync reports `master-password-changed`

### Requirement: Lock locks the account on screen, and disconnect asks first
The popup's Lock button MUST lock the account on screen and leave the other accounts as they are; a system lock still locks every account. Disconnecting MUST ask first, naming the account and saying that its app password is deleted in Nextcloud and its data removed from the browser. Nothing happens when the user declines.

#### Scenario: Lock one of two
@e2e exclude Browser-extension popup. Covered by tests/extension/lock.spec.js ("locks only the account on screen with the Lock button").
- **GIVEN** two unlocked accounts, the second on screen
- **WHEN** the user presses Lock
- **THEN** only the second account locks

#### Scenario: Decline a disconnect
@e2e exclude Browser-extension popup. Covered by tests/extension/lock.spec.js ("asks before disconnecting, and does nothing when the answer is no").
- **GIVEN** a paired account
- **WHEN** the user presses Disconnect and declines
- **THEN** the account stays paired; when they confirm, it is disconnected
