## ADDED Requirements

### Requirement: A key a partner cannot store stops the save
On the owner path the extension SHALL refuse a save, before anything is written, when its patch sets a non-empty `totp`, the row it was opened from held none, and a partner share the `incomplete` answer requires has a verified certificate whose `valueFields` does not list `totp`. The form MUST name those partners: "<cloud ids> use a Keepiq that cannot store an authenticator key. Nothing was saved. Remove the key or ask them to update Keepiq.", followed by "They need Keepiq <version> or later." when the server named the version. The server's 409 `partner_upgrade_required` MUST show the same text with its `requiredVersion`. A replaced or removed key MUST reach such a partner with `totp` null, since it never held one. A partner's `valueFields` is kept with its verified certificate in background memory, for as long as that certificate.

#### Scenario: Adding a key to a login shared with an older partner
- **GIVEN** Alice's "VPN" holds no seed and is shared with `bob@cloud.partner.example`, whose `valueFields` is empty
- **WHEN** she scans a key into it and saves
- **THEN** nothing MUST be saved and the form MUST name `bob@cloud.partner.example` and keep the key in the field

#### Scenario: Removing a key with an older partner
- **GIVEN** Alice's "VPN" holds a seed and is shared with `bob@cloud.partner.example`, whose `valueFields` is empty
- **WHEN** she removes the key and saves
- **THEN** the save MUST go through, with Bob's share carrying `totp` null

## MODIFIED Requirements

### Requirement: A value change saves every copy in one request
The extension SHALL save an edit whose body carries `key`, `login`, `additionalFields` or `totp` through `PUT /api/v1/secrets/{id}/share-save`, and MUST save any other edit through `PUT /api/v1/secrets/{id}` as ext-vault-edit specifies. It MUST read `GET /api/v1/secrets/{id}/write-context` first and take exactly one path from it: the owner path when `sourceSecretId` is the edited id, the copy-editor path when it is not and `effectiveGrade` is `owner`, `write` or `manage`, and otherwise none, since that copy is view-only. The first share-save MUST carry the patch, `baseUpdatedAt` and empty lists; on 409 `incomplete` the background MUST encrypt for what the answer requires, drop what it no longer requires and send again, and MUST fail the save with `incomplete` after three rounds, where each `incomplete` answer and each `stage_unknown` restart counts as one. Nothing about a save MUST outlive its request, beyond the form's record of what it opened, which a successful save drops, and verified partner certificates with their `valueFields`, kept in background memory for up to 5 minutes.

#### Scenario: Password change of an owned, shared item
- **GIVEN** Alice owns "Wifi" and shared it with Bob
- **WHEN** she changes its password in the extension and saves
- **THEN** the first share-save MUST carry no copies, the second MUST carry Bob's copy, and Alice's row and Bob's copy MUST change in the same request
- **AND** no `PUT /api/v1/secrets/{id}` and no `/sync` request MUST be sent

#### Scenario: Rename only
- **GIVEN** Alice owns "Wifi" and shared it with Bob
- **WHEN** she renames it and saves
- **THEN** `write-context` and `PUT /api/v1/secrets/{id}` MUST be sent, and no share-save

#### Scenario: Item that is not shared
- **GIVEN** Alice owns "Bank", which is shared with nobody
- **WHEN** she changes its password
- **THEN** one share-save with empty lists MUST save it, and no certificate MUST be looked up

#### Scenario: The share list keeps changing
- **GIVEN** the server answers three share-saves in a row with `incomplete`, naming a new recipient each time
- **WHEN** Alice saves a new password
- **THEN** the save MUST fail with `incomplete`, the form MUST stay open with "The people this item is shared with kept changing while it was saved. Nothing was saved; please try again.", and no fourth share-save MUST be sent

#### Scenario: Only the key changed
- **GIVEN** Alice owns "Wifi" and shared it with Bob
- **WHEN** she adds an authenticator key and saves nothing else
- **THEN** the change MUST go through share-save, with Bob's copy in the same request

### Requirement: An owner's change reaches every local copy
On the owner path the extension SHALL fill every copy the `incomplete` answer requires, whether direct, group or team-folder, read recipient certificates with `POST /api/v1/shares/recipient-certificates` in batches of at most 100 distinct user ids, and encrypt to each certificate with RSA-OAEP as ADR-003 defines. Each copy entry MUST carry `secretId` and exactly the value fields the patch carries: `key` when it changed, `login` when it changed (`null` when cleared), `additionalFields` when it changed and `totp` when it changed (`''` when removed), so an unchanged field is never cleared. A recipient answered `shareable: false` MUST be listed under `skipped` with `no_vault_key`; when the server refuses that skip, the background MUST read that recipient's certificate again once. A certificate that does not import MUST fail the save with `bad_certificate`. Use-only and expiring copies MUST be updated like any other, since their limits bind the holder, not the owner. Above 100 entries the save MUST go in staged chunks of at most 100 per list, committed by one final call.

#### Scenario: Group and team-folder copies
- **GIVEN** Alice shared "Wifi" directly with Bob, with the group Staff (Carol, Dan) and through the team folder Ops (Erin)
- **WHEN** she changes its password
- **THEN** one share-save MUST carry a copy entry for each of the four copies, each encrypted to that recipient's certificate

#### Scenario: Only the changed field
- **GIVEN** Alice changed only the username of a shared item
- **WHEN** the save fills the copies
- **THEN** each copy entry MUST contain `secretId` and `login` and MUST NOT contain `key`, `additionalFields` or `totp`

#### Scenario: Cleared username
- **GIVEN** Alice emptied the username of a shared item
- **WHEN** the save fills the copies
- **THEN** the patch and each copy entry MUST contain `"login": null`

#### Scenario: Removed key
- **GIVEN** Alice removed the authenticator key of a shared login
- **WHEN** the save fills the copies
- **THEN** the patch and each copy entry MUST contain `"totp": ""`

#### Scenario: Recipient without a vault key
- **GIVEN** Bob's suite was revoked, so the certificate lookup answers him as not shareable
- **WHEN** Alice changes the password
- **THEN** Bob's copy MUST be listed under `skipped` with `no_vault_key`, and the save MUST go through for everyone else

#### Scenario: Certificate that does not import
- **GIVEN** the server serves Carol a certificate that does not import
- **WHEN** Alice changes the password
- **THEN** nothing MUST be saved, and the form MUST say "Keepiq sent an encryption key for someone this item is shared with that this browser could not read. Nothing was saved."

#### Scenario: Many recipients
- **GIVEN** an item shared through a team folder with 230 members
- **WHEN** its owner changes the password
- **THEN** the certificates MUST be read in three requests of at most 100 user ids
- **AND** the copies MUST go in two chunks of 100 with `stage.final` `false` and a final call with the last 30 and the patch

### Requirement: Partner-organisation recipients get the whole value the form showed
On the owner path the extension SHALL, for each partner share the `incomplete` answer requires, look up the recipient's certificate with `POST /api/v1/federation/recipient-certificate` and `cloudId` set to the required share's `recipientCloudId`, verify it in the background, encrypt the whole `key`, `login` and `additionalFields` to it, and `totp` and `passkeys` when its `valueFields` lists each (null when it does not, or when the value is empty), and list it under `federated` with `certFingerprint`, the lowercase hex SHA-256 of the verified certificate. The whole value MUST be the typed fields plus each other field decrypted from the ciphertext the saving form's own edit fetch opened, found by the form's `recordId`; the extension MUST NOT decrypt a fresh row or a reply to build it. `key`, `login` and `additionalFields` count as opened only when the form showed them in full; `totp` and `passkeys` are opened whenever the record holds their ciphertext, since the form by design shows only that a key or passkeys are saved (ext-totp-capture), and their ciphertext was checked against the vault cache. When the form did not open every value field, its record is gone, or the record is of another version than the save's `baseUpdatedAt`, the save MUST fail with `unverified` before any partner lookup. A JSON field counts as opened in full in any writer's formatting, but never when one of its objects names a member twice or one of its numbers does not read back as written, since the form shows the parse and the partner gets the text. The edit fetch MUST refuse a row whose ciphertext of any of the five value fields the vault cache does not hold before or after a vault sync, so the form never opens another item's values.

#### Scenario: A password change reaches Bob at a partner
- **GIVEN** Alice shared "VPN" with `bob@cloud.partner.example`, whose share is active
- **WHEN** she changes its password
- **THEN** the extension MUST verify Bob's certificate and send his share the new value, login and additional fields encrypted to it, with its fingerprint, in the same share-save as her row

#### Scenario: A password change keeps a partner's code
- **GIVEN** Alice's "VPN" holds a seed and is shared with `bob@cloud.partner.example`, whose `valueFields` lists `totp`
- **WHEN** she changes only its password
- **THEN** Bob's share MUST carry `totp`, the stored seed encrypted to his certificate

#### Scenario: A field the form does not show
- **GIVEN** a card whose `key` holds a `recovery` member, which the card form has no field for, and a partner share with `bob@cloud.partner.example`
- **WHEN** Alice edits only the card's Notes and saves
- **THEN** nothing MUST be saved, no partner MUST be looked up, and the form MUST say "This item is shared with partner organisations, and this browser can't confirm they would get it exactly as you saw it. Nothing was saved; make this change in the Keepiq web app."

#### Scenario: The server answers the edit form with another item
- **GIVEN** the server answers the edit fetch for "Wifi" with the ciphertext of another item, before and after a vault sync
- **WHEN** Alice opens "Wifi" to edit it
- **THEN** nothing MUST be decrypted and the form MUST say "Keepiq sent a version of this item that your vault does not hold, so it was not opened."

#### Scenario: The server swaps in another item's seed
- **GIVEN** the server answers the edit fetch for "Wifi" with its own `key` but the `totp` ciphertext of another item, before and after a vault sync
- **WHEN** Alice opens "Wifi" to edit it
- **THEN** nothing MUST be decrypted and the form MUST say "Keepiq sent a version of this item that your vault does not hold, so it was not opened."

#### Scenario: A field the form shows on every type
- **GIVEN** a note with a login and a `notes` member in `additionalFields`, and a partner share
- **WHEN** Alice opens it for editing
- **THEN** the form MUST show Username and Notes with those values, as the Keepiq web app does
- **AND** a save MUST send the partner share the whole value as shown
