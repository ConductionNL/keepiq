## ADDED Requirements

### Requirement: Browse and search the vault
The apps MUST list the user's items, with their own items, shared items and team folders, and the folder tree as the web app shows them. Search MUST match names, URLs and user names on the device. The list MUST NOT decrypt secret values: it shows names and URLs, and decrypts an item when the user opens it.

#### Scenario: Find an item
- **GIVEN** an unlocked vault with 2,000 items
- **WHEN** the user types part of a name or domain into search
- **THEN** matching items appear while typing, without a server request

#### Scenario: Open a folder
- **GIVEN** a folder with subfolders
- **WHEN** the user opens it
- **THEN** its subfolders and items are listed, in the same order as the web app

### Requirement: View and copy an item
Opening an item MUST show its fields as its type defines them, with secret values hidden until the user reveals them. Copy MUST put the value on the clipboard marked as sensitive, so the system hides its preview, and clear it after the user's clipboard timeout (default 60 seconds). A TOTP item MUST show the current code and the seconds left.

#### Scenario: Copy a password
- **GIVEN** an open login item
- **WHEN** the user taps copy on the password
- **THEN** the password is on the clipboard without a visible preview, and is cleared after the timeout

#### Scenario: Use-only item
- **GIVEN** an item shared as use-only
- **WHEN** the user opens it
- **THEN** the app offers fill and copy where the share allows it, never reveal, and records the use with `POST /api/v1/secrets/{id}/used`

### Requirement: Create and edit items and folders
The apps MUST create, edit and move items and folders, and move items to the trash, through the same endpoints as the web app. Every write MUST encrypt the secret fields to the right suite's public key on the device before sending them. A write the server refuses MUST be shown as refused, never as saved.

#### Scenario: Create a login
- **GIVEN** an unlocked vault
- **WHEN** the user creates a login with a name, URL, user name and generated password
- **THEN** the item appears in the web app with the same values

#### Scenario: Edit a shared item
- **GIVEN** an item the user may edit that is shared with a team
- **WHEN** the user changes its password on the phone
- **THEN** the team members see the new password after their next sync

#### Scenario: Refused write
- **GIVEN** an item the user may only read
- **WHEN** the server refuses an edit
- **THEN** the app shows the refusal and keeps the old value

### Requirement: A value change reaches every copy
A change of an item's `key`, `login`, `additionalFields`, `totp` or `passkeys` MUST go through `PUT /api/v1/secrets/{id}/share-save`, as the web app and the extension save it: the row, every copy sealed to its holder's certificate, and every partner share sealed to a verified partner certificate, in one transaction or not at all (keepiq#1300). This holds for the edit form, an autofill password update and a passkey write. A change of only the name, address or folder MAY stay a plain `PUT /api/v1/secrets/{id}`. A copy whose holder has no write grade, as `GET /api/v1/secrets/{id}/write-context` grades it, MUST NOT offer Edit or passkey Delete, and any change of it but a move MUST be refused before anything is sent; while write-context has not answered, or could not, Edit and passkey Delete MUST NOT be enabled. Every item is encrypted to the same user key, so before an item is opened for edit or its passkeys are written, each value ciphertext the server sent MUST be the one the device holds for that item, after one sync, or on the passkey provider one read of the vault, when it is not yet (ADR-002); otherwise nothing is opened or written, and a failed sync is named as such. A partner share gets the whole value, so the save MUST vouch only for the fields the edit form shows in full, and fail as unverified on any other.

#### Scenario: Edit the password of a source with copies
- **GIVEN** a login the user owns, shared with two users
- **WHEN** the user changes its password on the phone
- **THEN** the save names both copies, each encrypted to its holder, and both users read the new password

#### Scenario: Edit as a team folder editor
- **GIVEN** a copy in a team folder where the user has the write grade
- **WHEN** the user changes its password on the phone
- **THEN** the save goes to the source on the version the form opened, and every copy gets the new password

#### Scenario: A copy shared to view only
- **GIVEN** a copy the user may only view
- **WHEN** the user opens it
- **THEN** the item shows no Edit and no passkey Delete, and says it is shared to view only

#### Scenario: A partner can't be reached
- **GIVEN** a login shared with a partner organisation that does not answer
- **WHEN** the user saves a new password
- **THEN** nothing is saved, the partner is named, and Save without them saves it for everyone else and pauses the partner share

#### Scenario: An autofill update of a login shared with a partner
- **GIVEN** a login shared with a partner organisation
- **WHEN** the system autofill offers to update its password
- **THEN** the update is refused, since the partner gets the whole value and no form showed it, and the phone says the password was not updated and that the app's edit form or the web app makes the change

#### Scenario: A value the device does not hold
- **GIVEN** an item whose ciphertext from the server differs from the one the device holds for it, also after a sync
- **WHEN** the user opens it for edit, or deletes a passkey on it
- **THEN** it is not opened or written, and the phone says the server sent a value it doesn't hold

#### Scenario: A passkey written to a login the device does not hold
- **GIVEN** a login whose ciphertext from the server differs from the one the device holds for it, also after reading the vault again
- **WHEN** a site registers a passkey that would go on it, or signs in with a passkey on it whose counter must be written
- **THEN** the new passkey goes on a new login, and the sign-in fails; nothing is written to that login

#### Scenario: A failed sync during the check
- **GIVEN** an item changed elsewhere since the last sync
- **WHEN** the user opens it for edit and the sync fails
- **THEN** the phone names the failure, such as being offline, and does not say the server sent a value it doesn't hold

#### Scenario: A card member the form does not show
- **GIVEN** a card shared with a partner organisation whose key holds a member the card form has no field for
- **WHEN** the user changes only its notes
- **THEN** nothing is saved, and the phone says to make the change in the web app

### Requirement: Offline reading
When the device is offline, the apps MUST open the vault from the local store with biometric, PIN or master-password unlock, show when it last synced, and refuse edits with a clear message. When the organisation turned offline caching off, the apps MUST say the vault needs a connection.

#### Scenario: Read a password on a plane
- **GIVEN** a synced vault and no network
- **WHEN** the user unlocks and opens an item
- **THEN** the item shows, with a note saying when the vault last synced

#### Scenario: Edit offline
- **GIVEN** no network
- **WHEN** the user tries to save a change
- **THEN** the app says edits need a connection and keeps the item unchanged
