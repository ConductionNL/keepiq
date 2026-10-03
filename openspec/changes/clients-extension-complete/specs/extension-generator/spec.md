## ADDED Requirements

### Requirement: Generator sub-tabs
The Generator tab MUST offer the sub-tabs Password, Passphrase and Username, open on the sub-tab last used for the account, generate a value when it opens and again when an option changes, and show the value in a monospace font with digits and special characters in their own colours, with Regenerate and Copy. Copy MUST copy the whole value.

#### Scenario: Switch to Username
@e2e exclude Browser-extension popup. Covered by tests/extension/popupVaultSendGenerator.spec.js ("makes passwords, passphrases and usernames") and live in Chromium.
- **GIVEN** the Generator tab open on Password
- **WHEN** the user chooses Username
- **THEN** a capitalised list word with four digits is shown

### Requirement: Password options
The Password sub-tab MUST offer a length from 8 to 128 (default 14), toggles for uppercase, lowercase, numbers and special characters (on, on, on, off), minimum numbers and minimum special characters (0 to 9, default 1), and avoiding ambiguous characters (on; leaves out I, O, l, 0 and 1). At least one class MUST stay on, the length MUST grow to fit the minimums, and the org policy MUST be applied.

#### Scenario: Avoid ambiguous characters
@e2e exclude Browser-extension popup. Covered by tests/vitest/generator-options.spec.js and live in Chromium.
- **GIVEN** the default options
- **WHEN** a password is generated
- **THEN** it has 14 characters and none of I, O, l, 0 or 1

### Requirement: Username generator
The Username sub-tab MUST offer Random word (a list word, capitalised, with four digits, both switchable), Plus addressed email (the account's email, prefilled from Nextcloud, with `+` and eight random characters or the website name before the `@`) and Catch-all email (a domain with eight random characters or the website name). Without an active website the website choice MUST be disabled with "No website detected".

#### Scenario: Plus-addressed email with the website name
@e2e exclude Browser-extension popup. Covered by tests/vitest/generator-options.spec.js and live in Chromium (admin+localhost@example.org).
- **GIVEN** the email `admin@example.org` and the active tab on `localhost`
- **WHEN** the user picks Plus addressed email with Website name
- **THEN** the value is `admin+localhost@example.org`

### Requirement: Generator history
The extension MUST keep the last 50 generated values per account with their kind and time, newest first, in session storage only, and MUST clear them when the account locks, when it is removed and when the browser restarts. A History view MUST list them with Copy per entry and Clear.

#### Scenario: Lock clears the history
@e2e exclude Browser-extension worker. Covered by tests/extension/generatorState.spec.js ("clears the history when the vault locks").
- **GIVEN** a generated value in the history
- **WHEN** the vault locks
- **THEN** the history is empty

### Requirement: Options remembered per account
The extension MUST store the generator options and the last sub-tab per account, sanitise them against the ranges and the policy when loading, and forget them when the account is removed.

#### Scenario: Options come back
@e2e exclude Browser-extension popup. Covered by tests/extension/popupVaultSendGenerator.spec.js ("remembers the options and the sub-tab").
- **GIVEN** the user set the length to 24 and chose Passphrase
- **WHEN** the popup opens again
- **THEN** Passphrase is selected and the length shows 24

### Requirement: Pick mode from the item form
From the item form the user MUST be able to open the Generator to pick a password or a username; "Use this value" MUST return it to the field that asked and show the form again with the rest of its input intact.

#### Scenario: Pick a password
@e2e exclude Browser-extension popup. Covered by tests/extension/popupVaultSendGenerator.spec.js (pick mode in "browses, reveals, edits and creates items").
- **GIVEN** a new item with a name and username typed
- **WHEN** the user generates a password and chooses Use this value
- **THEN** the password field holds it and the name and username are unchanged

### Requirement: Works while locked and offline
The Generator MUST be reachable from the lock screen and MUST generate, copy and keep history while locked and while the server is unreachable, using the last policy it saw. No generation path may need the vault key or the network.

#### Scenario: Generate while locked
@e2e exclude Browser-extension popup. Covered by tests/extension/popupVaultSendGenerator.spec.js ("generates while the vault is locked"), tests/extension/generatorState.spec.js (offline policy) and live in Chromium.
- **GIVEN** a locked extension
- **WHEN** the user chooses Generate a password on the lock screen
- **THEN** a value is generated
