## ADDED Requirements

### Requirement: A login's code on the detail screen
In the Android app, an item whose `totp` field holds a value, an Authenticator item included, MUST show its current code and the seconds left, with copy, as a standalone Authenticator item does (on an Authenticator item, under its own label next to the code of its `key`); a use-only copy MUST show and copy the code too. The seed MUST NOT be shown.

#### Scenario: A login with a code
- **GIVEN** a login with a seed in `totp`
- **WHEN** the user opens it on the phone
- **THEN** the detail screen shows the current code with its seconds left and a copy button
- **AND** the seed is not shown

#### Scenario: A use-only login with a code
- **GIVEN** a login shared as use-only that holds a seed
- **WHEN** the recipient opens it
- **THEN** the current code shows and copies

### Requirement: A login's passkeys on the detail screen
In the Android app, an item whose `passkeys` field holds credentials MUST show one row per passkey on its detail screen, with the date it was added in the phone's locale, and the account it is for, when it has one and the item holds more than one. A credential without a readable date MUST show that a passkey is saved. Passkeys MUST NOT be shown in the vault list or search, and the app MUST NOT show a passkey's private key, credential id or site. A use-only copy MUST NOT list passkeys.

#### Scenario: A login with a passkey
- **GIVEN** a login with one passkey added on 12 March 2026
- **WHEN** the user opens it on the phone
- **THEN** one passkey row reads "Added" and the date in the phone's locale, e.g. "Added March 12, 2026" in US English or "Toegevoegd op 12 maart 2026" in Dutch
- **AND** the vault list shows no passkey marker on the login

#### Scenario: Two passkeys on one login
- **GIVEN** a login with passkeys for the accounts `alice` and `alice-work`
- **WHEN** the user opens it
- **THEN** each passkey row names its account next to its added date

#### Scenario: A use-only login with a passkey
- **GIVEN** a login shared as use-only that holds a passkey
- **WHEN** the recipient opens it
- **THEN** no passkey row and no Delete is shown

### Requirement: Deleting a passkey from a login
In the Android app, where the user may edit the item and the phone is online, each passkey row MUST offer Delete behind a confirmation. Deleting MUST fetch the item fresh, remove every entry with that credential id and rpId, and save the others, or `''` when none remain, sending the fetched row's `updatedAt` as `baseUpdatedAt` so a passkey added elsewhere in between is never overwritten.

#### Scenario: Deleting a passkey
- **GIVEN** a login the user may edit, with two passkeys
- **WHEN** the user deletes one and confirms
- **THEN** the login keeps only the other passkey, in the web app as well

### Requirement: Focus after deleting a passkey
In the Android app, after a passkey is deleted, focus MUST move to the next passkey row, else the Edit button: input focus for keyboard and switch users, and accessibility focus while touch exploration is on, as with TalkBack.

#### Scenario: Focus after a delete with a keyboard
- **GIVEN** a login the user may edit, with three passkeys, used with a keyboard
- **WHEN** the user deletes the second and confirms
- **THEN** input focus is on the Delete of the passkey that took its place

#### Scenario: Focus after a delete with TalkBack on
- **GIVEN** TalkBack on and a login the user may edit, with three passkeys
- **WHEN** the user deletes the second and confirms
- **THEN** accessibility focus is on the Delete of the passkey that took its place

#### Scenario: Focus after the last delete
- **GIVEN** a login the user may edit, with one passkey
- **WHEN** the user deletes it and confirms
- **THEN** focus is on the Edit button

### Requirement: An Authenticator key on a login
In the Android app, the edit screen of a login MUST offer an Authenticator key field that takes a base32 secret or an `otpauth://totp/` URI and refuses a key the code generator cannot read before saving. The seed MUST be stored in the login's `totp` field, encrypted on the device, never in the additional fields. An edit MUST NOT send `passkeys`.

#### Scenario: Adding a key to a login
- **GIVEN** a login without a seed
- **WHEN** the user pastes an `otpauth://totp/` URI into the Authenticator key field and saves
- **THEN** the login shows the current code on its detail screen

#### Scenario: A key that gives no code
- **GIVEN** the Authenticator key field
- **WHEN** the user enters text that is neither a base32 secret nor an `otpauth://totp/` URI
- **THEN** the field shows that it is not an authenticator key and the login is not saved

#### Scenario: Editing a login without touching its passkeys
- **GIVEN** a login with a passkey
- **WHEN** the user changes only its name and saves
- **THEN** the update carries no `passkeys`, and the passkey is unchanged

### Requirement: A saved Authenticator key is write-only
In the Android app, a saved seed MUST NOT be loaded into the edit form: the field MUST show that a key is saved and offer Replace and Remove, Remove MUST send `''`, and an untouched field MUST send no `totp` value. On an item of another type whose `totp` holds a value, an Authenticator item included, the field MUST offer Remove only.

#### Scenario: Reopening a login with a saved key
- **GIVEN** a login with a seed
- **WHEN** the user opens its edit screen
- **THEN** the field shows that a key is saved, not the key, and offers Replace and Remove

#### Scenario: Editing a login without touching its key
- **GIVEN** a login with a seed
- **WHEN** the user changes only its name and saves
- **THEN** the update carries no `totp`, and the seed is unchanged

#### Scenario: Removing a saved key
- **GIVEN** a login with a seed
- **WHEN** the user chooses Remove and saves
- **THEN** the update sends `totp` as `''`

#### Scenario: Another type with a key
- **GIVEN** an Authenticator item whose own `totp` holds a value
- **WHEN** the user opens its edit screen
- **THEN** that field offers Remove only

### Requirement: No new Authenticator or Passkey items
The Android app MUST NOT offer the Authenticator or Passkey type for a new item (the iOS screens of this capability follow in keepiq#1353; until then iOS gets from this change only what the shared core gives it, and its edit view opens an item without decrypting a login's saved key). Existing Authenticator and Passkey items MUST keep opening, showing, filling and, for an Authenticator item, editing as before.

#### Scenario: Creating an item
- **GIVEN** an unlocked vault
- **WHEN** the user creates an item
- **THEN** the type list offers neither Authenticator nor Passkey

#### Scenario: An existing Authenticator item
- **GIVEN** an Authenticator item saved before this change
- **WHEN** the user opens it
- **THEN** it shows its current code as before
