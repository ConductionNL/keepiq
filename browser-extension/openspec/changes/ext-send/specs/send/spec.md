## ADDED Requirements

### Requirement: My sends list
The extension SHALL show the unlocked account's sends in the popup's Send tab, fetched from `GET /api/v1/sends` (ADR-003) on the first visit to the tab in a popup session and after every create or remove. The list is held in popup memory only and is discarded when the popup closes; it is never written to `storage.session` or `storage.local`. Each row shows a type icon (text or credential), the derived label, remaining views out of max views, the expiry date or "Never expires", a Password badge when the send is password protected, and the actions Copy link and Remove.

#### Scenario: Sends are listed newest first
- **GIVEN** the vault is unlocked and the server returns three sends
- **WHEN** the user opens the Send tab
- **THEN** three rows are shown in the server's order (newest first)
- **AND** each row shows its type icon, label, "2 of 5 views left" style counter, expiry, Password badge when applicable and the two actions

#### Scenario: Empty state
- **GIVEN** the server returns an empty list
- **WHEN** the user opens the Send tab
- **THEN** the list area shows "No sends yet" with a New send button

#### Scenario: List fails to load
- **GIVEN** the request to `GET /api/v1/sends` fails (offline or server error)
- **WHEN** the user opens the Send tab
- **THEN** an inline error "Could not load your sends" with a Retry action is shown instead of the rows
- **AND** no partial or stale list is shown

### Requirement: Row label derived from type and creation time
The extension SHALL label each send as "Text send" or "Credential send" followed by the creation date and time in the browser locale, derived from the row's `payloadType` and `createdAt`, because Keepiq sends have no name.

#### Scenario: Label for a credential send
- **GIVEN** a row with `payloadType` `credential` and `createdAt` `<iso8601>`
- **WHEN** the row is rendered
- **THEN** the label reads "Credential send, <localised date and time>"

### Requirement: Copy link only for sends created in this popup session
The extension SHALL keep the share link of each send it created in popup memory for the lifetime of the popup document, and SHALL offer Copy link only for those rows. Rows whose link is not in memory show "Link no longer available" in place of the action. Links are never written to any storage and are discarded when the popup closes.

#### Scenario: Copy link right after creation
- **GIVEN** the user created a send in this popup session and returned to the list
- **WHEN** they click Copy link on that row
- **THEN** the full share link, including the `#k=` fragment when the send has no password, is placed on the clipboard
- **AND** a "Copied" confirmation is shown

#### Scenario: Link of an older send
- **GIVEN** a row for a send created in an earlier popup session or in the web app
- **WHEN** the row is rendered
- **THEN** the Copy link action is replaced by the text "Link no longer available"

#### Scenario: Popup closes
- **GIVEN** a link is held in popup memory
- **WHEN** the popup closes
- **THEN** the link is gone and reopening the popup shows that row as "Link no longer available"

### Requirement: Remove a send
The extension SHALL revoke a send with `DELETE /api/v1/sends/{id}` after the user confirms, and SHALL remove the row on success.

#### Scenario: Confirmed removal
- **GIVEN** a row in the list
- **WHEN** the user clicks Remove and confirms "Remove this send? Anyone with the link loses access."
- **THEN** the extension calls `DELETE /api/v1/sends/{id}`
- **AND** on `{"revoked": true}` the row disappears and any link held for it in popup memory is discarded

#### Scenario: Send already gone
- **GIVEN** the server answers 404 because the send burned or expired meanwhile
- **WHEN** the removal response arrives
- **THEN** the row is removed as well and no error is shown

### Requirement: New send form
The extension SHALL offer a New send form with: Type (Text, default, or Credential), the content fields for the chosen type (one multi-line Text field, or Username and Password fields for Credential), Max views (default 1), Expiry (default 7 days), Password (optional, with show/hide toggle) and a Create button. Create is disabled while the content is empty. Field values live in popup memory only and are cleared when the form is submitted, cancelled or the popup closes.

#### Scenario: Default form
- **GIVEN** the user clicks New send
- **WHEN** the form opens
- **THEN** Type is Text, Max views is 1, Expiry is 7 days, Password is empty and Create is disabled

#### Scenario: Switching type
- **GIVEN** the form is open with Type Text
- **WHEN** the user selects Credential
- **THEN** the Text field is replaced by Username and Password fields
- **AND** Create stays disabled until the Password field has a value

### Requirement: Credential payload serialisation
The extension SHALL send a credential send with `payloadType` `credential` and a plaintext body of two lines, `Username: <username>` and `Password: <password>`, because the Keepiq recipient page renders the decrypted payload as plain text.

#### Scenario: Credential body
- **GIVEN** Username `<user>` and Password `<pass>` are filled in
- **WHEN** the user clicks Create
- **THEN** the plaintext handed to encryption is exactly `Username: <user>` newline `Password: <pass>`

### Requirement: Max views bounds
The extension SHALL accept Max views between 1 and 100 inclusive, matching the server cap, and SHALL show the server's error message when the server rejects the value anyway.

#### Scenario: Out of range in the form
- **GIVEN** the form is open
- **WHEN** the user enters 0 or 101
- **THEN** the field shows "Between 1 and 100" and Create is disabled

#### Scenario: Server rejects the request
- **GIVEN** the server answers 400 with `{"message": "<text>"}`
- **WHEN** the create response arrives
- **THEN** the form stays open with the values intact and shows `<text>` as the error

### Requirement: Expiry presets
The extension SHALL offer the expiry presets 1 hour, 1 day, 2 days, 3 days, 7 days, 30 days and Custom, and SHALL send the chosen duration to the server as `ttlSeconds`. Custom takes a whole number of hours from 1 to 720.

#### Scenario: Preset mapping
- **GIVEN** the user selects 3 days
- **WHEN** the send is created
- **THEN** the request carries `ttlSeconds` 259200

#### Scenario: Custom above the cap
- **GIVEN** the user selects Custom and enters 721 hours
- **WHEN** the value is validated
- **THEN** the field shows "At most 720 hours (30 days)" and Create is disabled

### Requirement: Client-side encryption and optional password
The extension MUST encrypt the payload in the background under a fresh random content key and MUST send only ciphertext and parameters to `POST /api/v1/sends`, in the format of ADR-003 "Ephemeral send payload". Without a password the content key travels in the `#k=` fragment of the share link and is never sent to the server. With a password the content key is wrapped under an Argon2id-derived key (parameters per ADR-003) and only `wrappedKey` and `argon2idSalt` reach the server. The content key and plaintext exist only in background memory during the create call and are dropped when the call returns.

#### Scenario: Send without password
- **GIVEN** the Password field is empty
- **WHEN** the user clicks Create
- **THEN** the request carries `hasPassword` false and no `wrappedKey` or `argon2idSalt`
- **AND** the resulting link ends in `#k=<base64url content key>`

#### Scenario: Send with password
- **GIVEN** the Password field has a value
- **WHEN** the user clicks Create
- **THEN** the form shows "Protecting with password..." while Argon2id runs
- **AND** the request carries `hasPassword` true, `wrappedKey` and `argon2idSalt`
- **AND** the resulting link has no fragment

#### Scenario: Argon2id unavailable
- **GIVEN** WebAssembly cannot be instantiated in the background
- **WHEN** the user fills in a Password
- **THEN** Create is disabled with "Password protection is not available in this browser" and the send can still be created without a password

### Requirement: Link shown once after creation
The extension SHALL show the share link with a Copy button immediately after a successful create, together with the note "Copy this link now. It is shown only once and the content burns after <n> view(s)." A Done action returns to the list.

#### Scenario: Result screen
- **GIVEN** `POST /api/v1/sends` answered 201
- **WHEN** the result screen renders
- **THEN** the link `<base>/public/send/<token>` (plus `#k=` fragment when no password) is shown in a read-only field with Copy
- **AND** clicking Copy places the link on the clipboard and shows "Copied"

### Requirement: Send from item
The extension SHALL offer "Send" in an item's More menu (ext-vault-browse) for items whose type is `login`, and SHALL open the New send form with Type Credential and Username and Password prefilled from the item's decrypted `login` and `key`. The decrypted values live in popup memory only and are cleared with the form.

#### Scenario: Prefill from a login item
- **GIVEN** the vault is unlocked and the user opens the More menu of a login item
- **WHEN** they choose Send
- **THEN** the Send tab opens on the New send form with Type Credential, Username and Password filled and all other fields at their defaults

#### Scenario: Blocked item
- **GIVEN** the item row has `blocked` true
- **WHEN** the More menu opens
- **THEN** Send is not offered

### Requirement: Unlocked and online only
The extension SHALL create sends only while the account is unlocked and the server is reachable. While offline the Create button and the Send action in the item menu are disabled with the explanation "Sends need a connection to your Keepiq server". A locked vault shows the unlock screen instead of the Send tab.

#### Scenario: Offline
- **GIVEN** the extension is offline as reported by the API client
- **WHEN** the user opens the New send form
- **THEN** the fields are editable but Create is disabled with the explanation

#### Scenario: Request fails mid-flight
- **GIVEN** the user clicked Create and the request fails with a network error
- **WHEN** the failure is reported
- **THEN** the form stays open with its values, shows "Could not reach the server, nothing was sent" and no send row is added

#### Scenario: Credentials revoked
- **GIVEN** the server answers 401 to any send request
- **WHEN** the response arrives
- **THEN** the account follows the 401 rule of ADR-002 and the popup shows the logged-out state

### Requirement: No persistence of send secrets
The extension MUST NOT write plaintext content, usernames, passwords, send passwords, content keys or share links to `storage.local`, `storage.session`, IndexedDB or any other persistent store. Plaintext and the send password live in popup memory (form) and in background memory during one `send.create` call; the content key lives in background memory during that call only; the link lives in popup memory until the popup closes; the sends list (server metadata only) lives in popup memory until the popup closes.

#### Scenario: Storage after a create
- **GIVEN** a send was created with and without a password
- **WHEN** `storage.local` and `storage.session` are inspected
- **THEN** neither contains any key under the send module and no value contains the content, the link or the fragment

#### Scenario: Worker restart
- **GIVEN** the background worker restarts after a create
- **WHEN** it wakes
- **THEN** it holds no send state and the popup's copy of the link is the only remaining copy
