## ADDED Requirements

### Requirement: A login's code and passkeys on the detail screen
In the Android app, an item whose `totp` field holds a value, an Authenticator item included, MUST show its current code and the seconds left, with copy, as a standalone Authenticator item does (on an Authenticator item, under its own label next to the code of its `key`); a use-only copy MUST show and copy the code too. The seed MUST NOT be shown. An item whose `passkeys` field holds credentials MUST show one row per passkey on its detail screen, with the date it was added in the phone's locale, and the account it is for, when it has one and the item holds more than one. A credential without a readable date MUST show that a passkey is saved. Passkeys MUST NOT be shown in the vault list or search, and the app MUST NOT show a passkey's private key, credential id or site. A use-only copy MUST NOT list passkeys.

Where the user may edit the item and the phone is online, each passkey row MUST offer Delete behind a confirmation. Deleting MUST fetch the item fresh, remove every entry with that credential id and rpId, and save the others, or `''` when none remain, sending the fetched row's `updatedAt` as `baseUpdatedAt` so a passkey added elsewhere in between is never overwritten. After a delete, focus MUST move to the next passkey row, else the Edit button.

#### Scenario: A login with a code and a passkey
- **GIVEN** a login with a seed in `totp` and one passkey added on 12 March 2026
- **WHEN** the user opens it on the phone
- **THEN** the detail screen shows the current code with its seconds left and a copy button
- **AND** one passkey row reads "Added" and the date in the phone's locale, e.g. "Added March 12, 2026" in US English or "Toegevoegd op 12 maart 2026" in Dutch
- **AND** the vault list shows no passkey marker on the login

#### Scenario: Two passkeys on one login
- **GIVEN** a login with passkeys for the accounts `alice` and `alice-work`
- **WHEN** the user opens it
- **THEN** each passkey row names its account next to its added date

#### Scenario: Deleting a passkey
- **GIVEN** a login the user may edit, with two passkeys
- **WHEN** the user deletes one and confirms
- **THEN** the login keeps only the other passkey, in the web app as well

#### Scenario: A use-only login with a code
- **GIVEN** a login shared as use-only that holds a seed and a passkey
- **WHEN** the recipient opens it
- **THEN** the current code shows and copies
- **AND** no passkey row and no Delete is shown

### Requirement: An Authenticator key on a login
In the Android app, the edit screen of a login MUST offer an Authenticator key field that takes a base32 secret or an `otpauth://totp/` URI and refuses a key the code generator cannot read before saving. The seed MUST be stored in the login's `totp` field, encrypted on the device, never in the additional fields. A saved seed MUST NOT be loaded into the form: the field MUST show that a key is saved and offer Replace and Remove, Remove MUST send `''`, and an untouched field MUST send no `totp` value. On an item of another type whose `totp` holds a value, an Authenticator item included, the field MUST offer Remove only. An edit MUST NOT send `passkeys`.

#### Scenario: Adding a key to a login
- **GIVEN** a login without a seed
- **WHEN** the user pastes an `otpauth://totp/` URI into the Authenticator key field and saves
- **THEN** the login shows the current code on its detail screen
- **AND** reopening the edit screen shows that a key is saved, not the key

#### Scenario: A key that gives no code
- **GIVEN** the Authenticator key field
- **WHEN** the user enters text that is neither a base32 secret nor an `otpauth://totp/` URI
- **THEN** the field shows that it is not an authenticator key and the login is not saved

#### Scenario: Editing a login without touching its key or passkeys
- **GIVEN** a login with a seed and a passkey
- **WHEN** the user changes only its name and saves
- **THEN** the update carries neither `totp` nor `passkeys`, and both are unchanged

### Requirement: No new Authenticator or Passkey items
The Android app MUST NOT offer the Authenticator or Passkey type for a new item (the iOS screens of this capability follow in a separate change; until then iOS gets from this change only what the shared core gives it). Existing Authenticator and Passkey items MUST keep opening, showing, filling and, for an Authenticator item, editing as before.

#### Scenario: Creating an item
- **GIVEN** an unlocked vault
- **WHEN** the user creates an item
- **THEN** the type list offers neither Authenticator nor Passkey

#### Scenario: An existing Authenticator item
- **GIVEN** an Authenticator item saved before this change
- **WHEN** the user opens it
- **THEN** it shows its current code as before
