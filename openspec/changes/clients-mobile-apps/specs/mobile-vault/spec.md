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
