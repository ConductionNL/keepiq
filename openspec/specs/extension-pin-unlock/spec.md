# extension-pin-unlock Specification

## Purpose
Unlocking the browser extension with a PIN for the browser session.

## Requirements

### Requirement: Unlock with a PIN until the browser closes
While an account is unlocked, Settings MUST offer to set a PIN of at least six characters, after the user enters the master password again. The worker MUST wrap the account's unlock key under a key derived from the PIN with Argon2id, and MUST keep the wrapped key in session storage only, so the PIN works until the browser closes and is never written to disk. The lock screen MUST then offer the PIN first and the master password below it. After five wrong PINs the PIN MUST be forgotten. When the master password changed, the wrapped key opens nothing: the PIN MUST be forgotten and the user told to unlock with the master password. Logging out, a revoked app password and disconnecting MUST forget the PIN. Settings MUST offer to remove it.

#### Scenario: Set and use a PIN
@e2e exclude Browser-extension worker and popup. Covered by tests/extension/pin.spec.js ("sets a PIN only with the right master password, and unlocks with it after a lock") and ("sets the PIN in Settings and unlocks with it on the lock screen").
- **GIVEN** an unlocked account
- **WHEN** the user sets a PIN with the right master password, locks, and enters the PIN
- **THEN** the vault unlocks; a wrong master password sets no PIN, and the wrapped key is only in session storage

#### Scenario: Five wrong PINs
@e2e exclude Browser-extension worker. Covered by tests/extension/pin.spec.js ("opens the key with the right PIN, counts wrong ones, and forgets the PIN after five").
- **GIVEN** a PIN
- **WHEN** a wrong PIN is entered five times
- **THEN** each try says how many are left, and the fifth forgets the PIN

#### Scenario: The master password changed
@e2e exclude Browser-extension worker. Covered by tests/extension/pin.spec.js ("forgets the PIN when the master password changed, and on log out").
- **GIVEN** a PIN set before the master password changed
- **WHEN** the user enters the PIN
- **THEN** the PIN is forgotten and the user is told to unlock with the master password; logging out forgets a PIN too
