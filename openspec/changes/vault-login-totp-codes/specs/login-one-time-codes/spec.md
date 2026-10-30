## ADDED Requirements

### Requirement: A login can carry its own TOTP seed

The system MUST let a vault user store a TOTP seed on a login-type secret, entered in the Authenticator key field of the create and edit dialogs as an `otpauth://totp/` URI or a base32 secret, or read from a QR image that is decoded in the browser. A QR image MUST NOT be uploaded or requested over the network, an image over 10 MB MUST be refused with a readable message before it is decoded, and only QR content that is a parseable `otpauth://totp/` URI MUST be accepted as a seed. A key that the TOTP generator cannot parse MUST be refused in the field before saving.

The seed MUST be stored in the secret's own `totp` field, RSA-encrypted in the browser with the holder's public key like `key`, `login` and `additionalFields` (chunked by the existing RSA encryption, so length is not capped by one RSA block). On the wire and at rest the field is null when it holds no value, the literal `''` when it is explicitly not set, and ciphertext otherwise; the web app MUST NOT send an encrypted empty string. The seed MUST NOT be stored in the additional fields, and an existing additional field named `totp`, `otp` or `otpauth` MUST be left as it is and MUST NOT be read as the seed.

After saving, the seed MUST NOT be shown again: not in the detail sidebar, the Additional fields box, the version details view, nor as a prefilled value in the edit dialog. The edit dialog MUST show that a key is saved and offer Replace and Remove; Replace sends the new encrypted seed, Remove sends `''`, and leaving the field untouched MUST send no `totp` value at all. Entering a key MUST be offered only for the Login type; on a row of another type whose `totp` holds a value, the edit dialog MUST show that a key is saved and offer Remove only. After choosing Replace, Remove or keeping the saved key, keyboard focus MUST move to the control that replaced the one chosen, never fall back to the page.

#### Scenario: A vault user adds a seed to a login

- **GIVEN** a vault user editing their login for `example.com` in the edit dialog
- **WHEN** the user pastes an `otpauth://totp/` URI into the Authenticator key field and saves
- **THEN** the request body carries the seed only as ciphertext in the `totp` field
- **AND** the additional fields are unchanged
- **AND** reopening the edit dialog shows "Authenticator key saved" with Replace and Remove, and not the seed

#### Scenario: A vault user reads the seed from a QR image

- **GIVEN** a vault user in the create dialog for a login
- **WHEN** the user picks an image of the QR code a site showed for two-factor setup
- **THEN** the image is decoded in the browser with no network request
- **AND** the decoded `otpauth://totp/` URI is put in the Authenticator key field

#### Scenario: A QR code that is not an authenticator key

- **GIVEN** a vault user in the Authenticator key field
- **WHEN** the user picks an image of a QR code holding a website address
- **THEN** the field shows that the QR code holds no authenticator key
- **AND** no seed is set

#### Scenario: Editing a login without touching the key

- **GIVEN** a login that holds a seed
- **WHEN** the user changes only its name in the edit dialog and saves
- **THEN** the request body carries no `totp` value
- **AND** the stored seed is unchanged

#### Scenario: Removing the key

- **GIVEN** a login that holds a seed
- **WHEN** the user chooses Remove in the edit dialog and saves
- **THEN** the request body carries `totp` as `''`
- **AND** the login no longer shows a code

#### Scenario: A QR image that is too large

- **GIVEN** a vault user in the Authenticator key field
- **WHEN** the user picks an image larger than 10 MB
- **THEN** the field shows that the image is too large and is not decoded
- **AND** no seed is set

#### Scenario: A saved key on an item that is not a login

- **GIVEN** an Authenticator item, made before this change, whose `totp` field holds a value
- **WHEN** the owner opens it in the edit dialog
- **THEN** the dialog shows that a key is saved and offers Remove only, with no field to enter a key

#### Scenario: A custom field that happens to be named totp

- **GIVEN** a login whose additional fields hold a field named `totp` from an earlier import
- **WHEN** the user opens the login
- **THEN** the field is listed in the Additional fields box as any other field
- **AND** no code row is derived from it

### Requirement: The login shows a live code

When a secret's decrypted `totp` field holds a seed, the secret detail sidebar MUST show the current code with its countdown and a copy button, computed in the browser by the existing TOTP generator, and MUST NOT show the seed. This read-only row MUST show for any secret type whose row holds a value, not only for logins. A seed that cannot be parsed MUST show the invalid-seed state and no code. The seed, any key derived from it and the code MUST NOT be sent to the server or kept in browser storage, and MUST be discarded on lock, as for the Authenticator item.

#### Scenario: A vault user reads the code of a login

- **GIVEN** a vault user whose login for `example.com` holds a seed in its `totp` field
- **WHEN** the user opens that login in the secret detail sidebar on /secrets
- **THEN** a 6-digit code and a countdown are shown with a copy button
- **AND** the seed text is shown nowhere on the page

#### Scenario: A malformed seed

- **GIVEN** a login whose `totp` field holds text that is not a TOTP URI or base32 secret
- **WHEN** the user opens the login
- **THEN** the code row shows that the seed is invalid and shows no code

### Requirement: A login carries its own passkeys

The system MUST let a login hold passkeys in its own `passkeys` field, RSA-encrypted in the browser like the other value fields, with the same null, `''` and ciphertext states as `totp`. The plaintext MUST be a JSON array of credential objects, each in the canonical credential schema a standalone passkey item holds in its `key`. Passkeys MUST NOT be typed into the web app; they are created by a passkey provider or an import.

The detail sidebar MUST list a secret's passkeys, for any type whose row holds a value, with relying party, user name and created date, and MUST NOT show or offer to copy any passkey's private key, in any mode. Where the holder may edit the login, each passkey MUST have a Delete action behind a confirmation; confirming MUST save every other entry exactly as stored, or `''` when none remain. After a delete, keyboard focus MUST move to the passkey list when passkeys remain, and otherwise to the Edit button or the detail itself.

#### Scenario: A vault user sees the passkeys of a login

- **GIVEN** a login whose `passkeys` field holds two credentials
- **WHEN** the user opens it in the secret detail sidebar
- **THEN** both passkeys are listed with relying party, user name and created date
- **AND** no private key, masked or otherwise, and no copy action for one is shown

#### Scenario: A vault user deletes one passkey

- **GIVEN** the owner of a login with two passkeys
- **WHEN** the owner chooses Delete on the first and confirms
- **THEN** the login is saved with only the second passkey, byte for byte as before
- **AND** cancelling the confirmation instead leaves both passkeys stored

### Requirement: Use-only and read-only copies of a login keep the seed and private keys hidden

On a use-only copy, the web app MUST show and allow copying the current code and MUST list the passkeys by their metadata, and MUST NOT show the seed or any passkey private key. On view-only, read-only and federated read-only copies, the code and passkey rows MUST follow the login's existing rules: no Delete action on a passkey and no editing of the seed.

#### Scenario: A use-only recipient copies the code

- **GIVEN** Bob with a use-only copy of a login that holds a seed and a passkey
- **WHEN** he opens it in the secret list at /secrets
- **THEN** he can see and copy the current code
- **AND** the passkey is listed with relying party, user name and created date
- **AND** neither the seed nor the private key is shown, and no Delete action is offered

### Requirement: Version history keeps the code and passkeys

Each version of a secret MUST carry its `totp` and `passkeys` values, and restoring a version MUST restore both. The version details view MUST show only "Authenticator key set" for a version that holds a seed, never the seed, and MUST list a version's passkeys by their metadata only.

#### Scenario: Restoring a version restores the seed

- **GIVEN** a login whose seed was removed after version 3 was saved
- **WHEN** the owner restores version 3
- **THEN** the login holds version 3's seed again and shows a code

#### Scenario: Restoring a version without a seed takes the seed away

- **GIVEN** a login that had no seed when version 3 was saved, and gained one after
- **WHEN** the owner restores version 3
- **THEN** the login holds no seed, on the row, on every copy and on every partner share
- **AND** the same holds for the passkeys

#### Scenario: A version from before the fields existed leaves them alone

- **GIVEN** a version saved before a secret could hold a seed or passkeys, which records neither as empty
- **WHEN** the owner restores it
- **THEN** the login keeps the seed and passkeys it holds

#### Scenario: The version details hide the seed

- **GIVEN** a version of a login that holds a seed and a passkey
- **WHEN** the owner opens its details
- **THEN** "Authenticator key set" is shown and not the seed
- **AND** the passkey is listed without its private key

### Requirement: Every copy of a login carries the code and passkeys

The share-save of an edit, direct user shares, group shares, team-folder shares, ownership transfer, the offline cache and offline edit queue, and encryption suite migration MUST carry `totp` and `passkeys` with the other value fields, each re-encrypted for its target key in the browser. A copy of a login without a value MUST carry null, never an encrypted empty string.

#### Scenario: A shared login gets its seed on the copy

- **GIVEN** the owner of a login with a seed shares it with Bob
- **WHEN** Bob opens his copy
- **THEN** his copy shows the same live code

#### Scenario: An edit made offline reaches the copies

- **GIVEN** an owner offline who removes the seed of a shared login
- **WHEN** the queued edit replays after reconnecting
- **THEN** the owner's row and every copy carry `totp` as `''`

### Requirement: Federated partners only receive fields they store

The web app MUST send `totp` and `passkeys` to a federated partner only when the partner advertises that it stores them. A patch that sets neither field MUST reach a partner that does not advertise them with both fields null. Setting a value, or newly sharing a login that already holds a value, to such a partner MUST be refused with a readable message that names the Keepiq release the partner needs; the server refuses it with 409 `partner_upgrade_required`, and the web app MUST show that message for the refusal.

#### Scenario: Sharing a login with a seed to an older partner

- **GIVEN** a login that holds a seed and a federated partner on an older Keepiq release
- **WHEN** the owner shares the login with a user on that partner
- **THEN** nothing is shared
- **AND** the owner reads that the partner needs a newer Keepiq release before a login with a code or passkeys can be shared with it

### Requirement: Link shares leave the code and passkeys out

A public link share MUST NOT carry `totp` or `passkeys`. A link recipient decrypts the whole shared value in their browser, so carrying them would hand out the seed and the passkey private keys.

#### Scenario: A link share of a login with a seed

- **GIVEN** a login that holds a seed and a passkey
- **WHEN** the owner creates a public link share of it
- **THEN** the encrypted link payload holds neither the seed nor the passkeys

### Requirement: New items are not created as Authenticator or Passkey items

The create dialog and the default item type setting MUST NOT offer the Authenticator (`totp`) and Passkey (`passkey`) types. A saved default of either type MUST fall back to Login. Existing items of those types MUST keep showing, editing and sharing as before and MUST stay in the vault list's type filter.

#### Scenario: A saved default of Authenticator

- **GIVEN** a user whose saved default item type is Authenticator
- **WHEN** the user opens the create dialog
- **THEN** Login is preselected and Authenticator is not in the type list

#### Scenario: An existing Authenticator item

- **GIVEN** a vault with an Authenticator item made before this change
- **WHEN** the user filters the vault by the Authenticator type and opens the item
- **THEN** the item is listed, shows its code and can be edited and shared

### Requirement: The vault list marks logins with a code or passkeys

A vault row whose `totp` or `passkeys` field holds a value MUST carry a small marker for each, in the list, card and table views, with a text alternative for assistive technology, and in the list and card views the row's accessible name MUST say that the item has a one-time code, passkeys, or both. The marker MUST be derived from the presence of ciphertext only, without decrypting it.

#### Scenario: A login with a code in the table view

- **GIVEN** a login that holds a seed and no passkeys
- **WHEN** the user views the vault as a table
- **THEN** the login's row shows the one-time code marker, announced as "Has a one-time code"
- **AND** no passkeys marker

#### Scenario: A screen reader user in the list view

- **GIVEN** a login named GitHub that holds a seed and no passkeys
- **WHEN** a screen reader user moves to its row in the list view
- **THEN** the row is announced as "Open GitHub, has a one-time code"

### Requirement: Password health ignores seeds and passkeys

The password health analysis MUST NOT decrypt, score, reuse-match or breach-check a secret's `totp` or `passkeys` value; it reads only `key`.

#### Scenario: A login with a seed in a health run

- **GIVEN** a login with a password and a seed
- **WHEN** password health runs
- **THEN** only the password is analysed

### Requirement: The login fields wait for a server that stores them

The web app MUST offer the Authenticator key field and the passkey Delete action only when the server declares, in the `loginValueFields` initial state, that it stores `totp` and `passkeys`; the read-only code and passkey rows depend only on the row holding a value. The web app MUST keep no release number of its own: the release that first stored the fields, named in the message for a partner that must upgrade, comes from that same initial state. No new Nextcloud OCP interface is used; session and user handling are those of the existing secret views.

#### Scenario: A web app served by an older server

- **GIVEN** a web app served by a server that does not declare `loginValueFields`
- **WHEN** the user opens the create dialog for a login
- **THEN** no Authenticator key field is shown

### Requirement: Import and export keep a login's code and passkeys on it

An import MUST put a one-time code and passkeys found with a login on that login's own `totp` and `passkeys` fields, encrypted in the browser like the other value fields, and MUST NOT create separate `(TOTP)` or `(passkey)` items. A seed MUST go on `totp` only when the TOTP parser reads it, an `otpauth://totp/` URI or a base32 secret, normalised as the create dialog stores an entered key; any other value (a Steam code, an HOTP URI, junk) MUST stay an additional field in Bitwarden, CSV and KeePass imports, and MUST be rejected with a reason in a CXF import. Seed parameters given one by one (CXF 1.0 `secret`, `period`, `digits`, `algorithm`; KeePass `TimeOtp-Secret-Base32`, `TimeOtp-Period`, `TimeOtp-Length`, `TimeOtp-Algorithm`) MUST be stored as the bare base32 secret when period, digits and algorithm are 30, 6 and SHA1 and no issuer or account is given, and as an otpauth URI carrying them otherwise, so the code display keeps its issuer and account; KeePass parameter strings folded into the seed MUST NOT also stay additional fields. A CXF TOTP with an algorithm or digit count the code generator cannot use MUST be rejected with a reason, never stored as a wrong code; KeePass strings of such a seed stay additional fields. Passkeys MUST be stored as a JSON array in the canonical credential schema. A row without a code or passkeys MUST send neither field.

Bitwarden's `login.totp` and `login.fido2Credentials[]` and KeePass's `otp` and `TimeOtp-*` strings MUST go to the entry's login; a different second KeePass seed MUST stay an additional field. A Bitwarden login without a URI that brings passkeys MUST take the first passkey's RP id as its URL. In a CXF item, whatever the order of its credentials, a TOTP or passkey credential MUST go on the item's first accepted login, else on its first accepted other row (a note or key), else on a new login with an empty password named after the item. A code or passkey MUST NOT go on a rejected row: when the item's login is rejected for its own reason (such as a missing name), they go on the new login instead. The first seed of an item MUST win: an identical one is the same code, and a different one MUST land in the rejected list with a reason. A passkey with the credential id, RP id and private key of one already on the row MUST be folded in; one with the same credential id and RP id but another private key MUST land in the rejected list with a reason. A passkey entry without a credential id, RP id or key material MUST be rejected with a reason while its login still imports. CXF passkeys MUST be read from `username` (CXF 1.0) or `userName` (earlier Keepiq exports). The import preview and the duplicates list MUST mark a row that brings a code or passkeys.

A CXF export MUST write a login's code and passkeys as credentials of that login's own CXF item, in the CXF 1.0 shape (TOTP: base32 `secret`, `period`, `digits`, lower-case `algorithm`, and `username` and `issuer` when the URI names them; passkey: `username`), so that a re-import gives the same login back with the same code, and MUST report a stored code or passkey it cannot export in the unmapped-item list, the counter and transports loss once per secret. Standalone Authenticator and Passkey items MUST keep exporting as items of their own; on re-import they come back as logins. The encrypted backup and the GDPR package MUST carry both fields when they are set, and a backup restore MUST put them back on the login. The plaintext CSV export MUST write a login's seed in a `totp` column that the generic CSV import maps back; it has no column for passkeys and leaves them out, which `docs/importing.md` and `docs/gdpr.md` state.

Known limitations, not changed here:

- Duplicate detection still matches on name and URL only, so under the default "skip" an imported row with a code or passkeys is skipped when a vault login with the same name and URL lacks them; the duplicates list marks such rows so the user can import them as copies.
- The password policy check runs on the password only; a row whose password is below the policy is rejected with its code and passkeys, under the policy's reason.
- A CXF export drops a passkey's `algorithm`, `createdAt`, `counter` and `transports`, and a login's notes, as before; a CXF import gives a passkey Keepiq's defaults for them.
- A CXF round trip keeps a seed's secret, parameters, issuer and account, but not the exact text of its otpauth URI (parameter order, other query parameters).

#### Scenario: A Bitwarden login with a code and a passkey

- **GIVEN** a Bitwarden JSON export with a login that has a `totp` and one complete `fido2Credentials` entry
- **WHEN** the user imports it
- **THEN** one login is created whose encrypted `totp` holds the seed and whose encrypted `passkeys` holds the credential
- **AND** no `(TOTP)` or `(passkey)` item is created

#### Scenario: A KeePassXC entry with a code

- **GIVEN** a KeePass XML export with an entry whose `otp` string holds an otpauth URI
- **WHEN** the user imports it
- **THEN** the entry's login holds that URI in its `totp` field and the URI is not among its additional fields

#### Scenario: A CXF item with only a TOTP

- **GIVEN** a CXF item whose only credential is a TOTP
- **WHEN** the user imports it
- **THEN** a login with the item's title, an empty password and that seed in `totp` is created

#### Scenario: A CXF item with two different TOTPs

- **GIVEN** a CXF item with a login and two TOTP credentials with different seeds
- **WHEN** the user imports it
- **THEN** the login holds the first seed
- **AND** the second appears in the rejected list with a reason

#### Scenario: A CXF round trip

- **GIVEN** a login with a password, a seed and two passkeys
- **WHEN** the user exports it as CXF and imports the file again
- **THEN** one login comes back with the same password, seed and passkeys

#### Scenario: A backup restore

- **GIVEN** an encrypted backup of a login with a seed and passkeys
- **WHEN** the user restores it
- **THEN** the restored login holds the same seed and passkeys

#### Scenario: A Steam code in a Bitwarden login

- **GIVEN** a Bitwarden login whose `totp` is `steam://` followed by a secret
- **WHEN** the user imports it
- **THEN** the login has no `totp` value and the Steam code is kept as an additional field named `totp`

#### Scenario: A CXF TOTP with an unknown algorithm

- **GIVEN** a CXF item with a login and a TOTP whose `algorithm` is not `sha1`, `sha256` or `sha512`
- **WHEN** the user imports it
- **THEN** the login is imported without a code
- **AND** the TOTP appears in the rejected list with a reason

#### Scenario: A CXF note carrying a code

- **GIVEN** a CXF item holding a note and a TOTP
- **WHEN** the user imports it
- **THEN** one note is created that holds the seed in `totp`

#### Scenario: A CSV round trip of a code

- **GIVEN** a login with a seed
- **WHEN** the user exports a plaintext CSV and imports it as a generic CSV
- **THEN** the `totp` column is mapped to the Authenticator key and the login holds the same seed
