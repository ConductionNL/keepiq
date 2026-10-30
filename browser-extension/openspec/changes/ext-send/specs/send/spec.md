## ADDED Requirements

### Requirement: My sends list
The extension SHALL show the unlocked account's sends in the popup's Send tab, fetched from `GET /api/v1/sends` (ADR-003) on the first visit to the tab in a popup session and after every create or remove. The list is held in popup memory only and is discarded when the popup closes, the vault locks or the active account changes; it is never written to `storage.session` or `storage.local`. Each row shows a type icon (text or credential), the derived label, remaining views out of max views and, on its own line, the expiry date, "Never expires" when `expiresAt` is null or "Expired" when it is in the past, a Password badge when the send is password protected, and the actions Copy link and Remove. Dates are short and 24-hour, with the year only when it is not the current one. The label wraps rather than being cut off. Where two rows share a label, the accessible names of their buttons get a number, so each is unique.

#### Scenario: Sends are listed newest first
- **GIVEN** the vault is unlocked and the server returns three sends
- **WHEN** the user opens the Send tab
- **THEN** three rows are shown in the server's order (newest first)
- **AND** each row shows its type icon, label, "2 of 5 views left" style counter, expiry, Password badge when applicable and the two actions

#### Scenario: Expired send not yet purged
- **GIVEN** the server returns a send whose `expiresAt` is in the past
- **WHEN** the row is rendered
- **THEN** it shows "Expired" in place of the expiry date and offers Remove but not Copy link

#### Scenario: Send without a time limit
- **GIVEN** the server returns a send with `expiresAt` null, as the web app can create
- **WHEN** the row is rendered
- **THEN** it shows "Never expires"

#### Scenario: Empty state
- **GIVEN** the server returns an empty list
- **WHEN** the user opens the Send tab
- **THEN** the list area shows "No sends yet", the line "Share text or a login through a link that deletes itself." and a New send button

#### Scenario: List fails to load
- **GIVEN** the request to `GET /api/v1/sends` fails (offline or server error)
- **WHEN** the user opens the Send tab
- **THEN** an inline error "Could not load your sends" with a Retry action is shown instead of the rows
- **AND** no partial or stale list is shown

#### Scenario: Retry
- **GIVEN** the list failed to load
- **WHEN** the user clicks Retry
- **THEN** the error makes way for the loading state, and a repeated failure is announced again

### Requirement: Row label derived from type and creation time
The extension SHALL label each send as "Text send" or "Credential send" followed by the creation date and time in the popup's language, derived from the row's `payloadType` and `createdAt`, because Keepiq sends have no name.

#### Scenario: Label for a credential send
- **GIVEN** a row with `payloadType` `credential` and `createdAt` `<iso8601>`
- **WHEN** the row is rendered
- **THEN** the label reads "Credential send, <localised short date and 24-hour time>"

### Requirement: Copy link
The extension SHALL offer Copy link for a send with a password at any time, using the link the background builds from the row's `token`, because that link holds no secret. For a send without a password it SHALL keep the link, which carries the content key in its `#k=` fragment, in popup memory only from its creation until the popup closes, the vault locks or the active account changes, and SHALL offer Copy link only while it holds it. Rows without a copyable link show "Link no longer available" in place of the action. Links are never written to any storage.

#### Scenario: Copy link right after creation
- **GIVEN** the user created a send without a password in this popup session and returned to the list
- **WHEN** they click Copy link on that row
- **THEN** the full share link including the `#k=` fragment is placed on the clipboard
- **AND** a "Link copied" confirmation is shown

#### Scenario: Password send from an earlier session
- **GIVEN** a row for a password-protected send created in an earlier popup session or in the web app
- **WHEN** the user clicks Copy link
- **THEN** the link `<base>/index.php/apps/keepiq/public/send/<token>` without a fragment is placed on the clipboard

#### Scenario: Send without a password from an earlier session
- **GIVEN** a row for a send without a password created in an earlier popup session or in the web app
- **WHEN** the row is rendered
- **THEN** the Copy link action is replaced by the text "Link no longer available"

#### Scenario: Popup closes
- **GIVEN** the link of a send without a password is held in popup memory
- **WHEN** the popup closes
- **THEN** the link is gone and reopening the popup shows that row as "Link no longer available"

#### Scenario: Account switch or lock
- **GIVEN** the link of a send without a password is held in popup memory
- **WHEN** the user switches to another account or the vault locks
- **THEN** the link is discarded, and returning to the first account shows that row as "Link no longer available"

### Requirement: Remove a send
The extension SHALL revoke a send with `DELETE /api/v1/sends/{id}` after the user confirms, and SHALL remove the row on success with a "Send removed" toast. Focus then moves to the next row, or to New send when the list is empty.

#### Scenario: Confirmed removal
- **GIVEN** a row in the list
- **WHEN** the user clicks Remove and confirms "Remove this send? Anyone with the link loses access."
- **THEN** the extension calls `DELETE /api/v1/sends/{id}`
- **AND** on success the row disappears, any link held for it in popup memory is discarded, "Send removed" is shown and focus moves to the next row

#### Scenario: Send already gone
- **GIVEN** the server answers 404 because the send burned or expired meanwhile
- **WHEN** the removal response arrives
- **THEN** the row is removed as well and no error is shown

### Requirement: New send form
The extension SHALL offer a New send form with: Type (Text, default, or Credential), the content fields for the chosen type (one multi-line "Text to share" field, or Username and Password fields for Credential), Max views (default 1), Expiry (default 7 days), Access password (optional, with show/hide toggle and the hint "Optional. The recipient needs it to open the send.") and a Create button. Create is disabled while the content is empty or blank, and for Credential while the Password is blank. The content has no length limit beyond what the server accepts. Field values live in popup memory only and are cleared when the form is submitted, cancelled or the popup closes. Focus starts in the first content field.

#### Scenario: Default form
- **GIVEN** the user clicks New send
- **WHEN** the form opens
- **THEN** Type is Text, Max views is 1, Expiry is 7 days, Access password is empty, Create is disabled and focus is in the Text to share field

#### Scenario: Switching type
- **GIVEN** the form is open with Type Text
- **WHEN** the user selects Credential
- **THEN** the Text field is replaced by Username and Password fields
- **AND** Create stays disabled until the Password field has a value

#### Scenario: Leaving a changed form
- **GIVEN** any field, an option included, differs from the defaults or from the values an item's Send filled in
- **WHEN** the user navigates back or to another tab
- **THEN** the popup asks to discard the changes, as the item form does

#### Scenario: Leaving an unchanged prefilled form
- **GIVEN** the form was opened from an item's Send and nothing was changed
- **WHEN** the user navigates back
- **THEN** the form closes without asking

#### Scenario: Leaving while a create runs
- **GIVEN** the user clicked Create and the reply has not arrived
- **WHEN** the user tries Back, Cancel, Pop out or the account switcher
- **THEN** those controls are disabled and the form stays

#### Scenario: Reply after the form is gone
- **GIVEN** the form was closed while a create ran, for example by a lock
- **WHEN** the reply arrives
- **THEN** no result screen opens and the link appears nowhere in the popup's page

### Requirement: Credential payload serialisation
The extension SHALL send a credential send with `payloadType` `credential` and a plaintext body of two lines, `Username: <username>` and `Password: <password>`, with the labels in English whatever the popup's language, because the Keepiq recipient page renders the decrypted payload as plain text.

#### Scenario: Credential body
- **GIVEN** Username `<user>` and Password `<pass>` are filled in and the popup is in Dutch
- **WHEN** the user clicks Create
- **THEN** the plaintext handed to encryption is exactly `Username: <user>` newline `Password: <pass>`

#### Scenario: Credential without a password reaches the background
- **GIVEN** a `send.create` whose credential body has a blank password
- **WHEN** the background checks it
- **THEN** it answers `invalid` and sends nothing

### Requirement: Max views bounds
The extension SHALL accept Max views between 1 and 100 inclusive, matching the server cap.

#### Scenario: Out of range in the form
- **GIVEN** the form is open
- **WHEN** the user enters 0 or 101
- **THEN** the field shows "Between 1 and 100" and Create is disabled

#### Scenario: Server rejects the request
- **GIVEN** the server answers 400 to the create
- **WHEN** the response arrives
- **THEN** the form stays open with the values intact and shows the localized text for the `invalid` code
- **AND** the server's own message is not shown

### Requirement: Expiry presets
The extension SHALL offer the expiry presets 1 hour, 1 day, 2 days, 3 days, 7 days, 30 days and Custom, and SHALL send the chosen duration to the server as `ttlSeconds`. Custom takes a whole number of hours from 1 to 720. A send without a time limit is not offered, and the background refuses a `ttlSeconds` below 3600 or above 2592000 with `invalid`.

#### Scenario: Preset mapping
- **GIVEN** the user selects 3 days
- **WHEN** the send is created
- **THEN** the request carries `ttlSeconds` 259200

#### Scenario: Custom above the cap
- **GIVEN** the user selects Custom and enters 721 hours
- **WHEN** the value is validated
- **THEN** the field shows "At most 720 hours (30 days)" and Create is disabled

#### Scenario: No time limit reaches the background
- **GIVEN** a `send.create` with `ttlSeconds` 0
- **WHEN** the background checks it
- **THEN** it answers `invalid` and sends nothing

### Requirement: Client-side encryption and optional password
The extension MUST encrypt the payload in the background under a fresh random content key and MUST send only ciphertext and parameters to `POST /api/v1/sends`, in the format of ADR-003 "Ephemeral send payload" and the vectors in `tests/vectors/crypto/send.json`. Without a password the content key travels in the `#k=` fragment of the share link and is never sent to the server. With a password the content key is wrapped under an Argon2id-derived key (parameters per ADR-003) and only `wrappedKey` and `argon2idSalt` reach the server. The content key and plaintext exist only in background memory during the create call and are dropped when the call returns. A lock, logout or account switch while Argon2id runs stops the create before the request goes out.

#### Scenario: Send without password
- **GIVEN** the Access password field is empty
- **WHEN** the user clicks Create
- **THEN** the request carries `hasPassword` false and no `wrappedKey` or `argon2idSalt`
- **AND** the resulting link ends in `#k=<base64url content key without padding>`

#### Scenario: Send with password
- **GIVEN** the Access password field has a value
- **WHEN** the user clicks Create
- **THEN** the form shows "Protecting with password…" while Argon2id runs
- **AND** the request carries `hasPassword` true, `wrappedKey` and `argon2idSalt`
- **AND** the resulting link has no fragment

#### Scenario: Locked while Argon2id runs
- **GIVEN** the user clicked Create on a password send
- **WHEN** the vault locks, the account logs out or another account becomes active before Argon2id finishes
- **THEN** no request is sent and the reply is `locked` or `account_changed`

#### Scenario: Argon2id unavailable
- **GIVEN** WebAssembly cannot be compiled in the background
- **WHEN** the user fills in an Access password
- **THEN** Create is disabled with "Password protection is not available in this browser" and the send can still be created without a password

#### Scenario: Argon2id fails after the check
- **GIVEN** the WebAssembly check passed but the derivation fails, for example when its memory can't be had
- **WHEN** the user creates a password send
- **THEN** nothing is sent, the reply is `argon2_unavailable` and the offline state is left as it was

### Requirement: Link shown after creation
The extension SHALL show the heading "Your send is ready" with the send's label, and the share link in a read-only field with a Copy link button, immediately after a successful create, with focus on the heading. For a send without a password it adds the note "Copy this link now. It is shown only until you close this window, and the content burns after <n> view(s)." For a password send it adds "Share the access password separately from the link. After 5 wrong attempts the send is deleted." A Done action returns to the refreshed list with focus on the new send's row.

#### Scenario: Result screen
- **GIVEN** `POST /api/v1/sends` answered 201
- **WHEN** the result screen renders
- **THEN** "Your send is ready" and the link `<base>/index.php/apps/keepiq/public/send/<token>` (plus `#k=` fragment when no password) are shown, the link in a read-only field with Copy link
- **AND** clicking Copy link places the link on the clipboard and shows "Link copied"

#### Scenario: Password send result
- **GIVEN** the send was created with an access password
- **WHEN** the result screen renders
- **THEN** it shows the note to share the access password separately and that 5 wrong attempts delete the send

### Requirement: Send from item
The extension SHALL offer "Send" in an item's More menu, in the list row and in the item detail, for items whose type is `login`, and SHALL open the New send form with Type Credential and Username and Password prefilled from the item's decrypted `login` and `key`. For other types Send is not shown. For a blocked item, a use-only shared copy, a share whose access expires, or while offline, Send is shown disabled with the reason. A read-only share may be sent. A login with no stored password opens with the Password empty. The decrypted values live in popup memory only and are cleared with the form.

#### Scenario: Prefill from a login item
- **GIVEN** the vault is unlocked and the user opens the More menu of a login item
- **WHEN** they choose Send
- **THEN** the Send tab opens on the New send form with Type Credential, Username and Password filled and all other fields at their defaults

#### Scenario: Blocked item
- **GIVEN** the item row has `blocked` true
- **WHEN** the More menu opens
- **THEN** Send is shown disabled with the blocked reason

#### Scenario: Use-only copy
- **GIVEN** the item is a use-only shared copy
- **WHEN** the More menu opens
- **THEN** Send is shown disabled with the use-only reason, since its password can't be revealed

#### Scenario: Expiring share
- **GIVEN** the item is a shared copy whose access ends on a date
- **WHEN** the More menu opens
- **THEN** Send is shown disabled with "Your access ends <date>, so it cannot be sent", since a send would outlive the access

#### Scenario: Not a login
- **GIVEN** the item's type is not `login`
- **WHEN** the More menu opens
- **THEN** Send is not shown

### Requirement: Unlocked and online only
The extension SHALL create sends only while the account is unlocked and the server is reachable. While offline, meaning the last request to the server found none, the Create button and the Send action in the item menu are disabled with the explanation "Sends need a connection to your Keepiq server", and the form shows that explanation in a banner with Sync now. The background does not refuse on the offline flag; it tries the request and answers `offline` when the server can't be reached. A locked vault shows the unlock screen instead of the Send tab.

#### Scenario: Offline
- **GIVEN** the extension is offline
- **WHEN** the user opens the New send form
- **THEN** the fields are editable, Create is disabled with the explanation and a banner offers Sync now

#### Scenario: Request fails mid-flight
- **GIVEN** the user clicked Create and the request fails with a network error
- **WHEN** the failure is reported
- **THEN** the form stays open with its values, shows "Could not reach the server, nothing was sent" and no send row is added

#### Scenario: Vault locked meanwhile
- **GIVEN** the vault locked while the form was open
- **WHEN** the user clicks Create
- **THEN** nothing is sent and the popup shows the unlock screen

#### Scenario: Credentials revoked
- **GIVEN** the server answers 401 to any send request
- **WHEN** the response arrives
- **THEN** the account follows the 401 rule of ADR-002 and the popup shows the logged-out state

### Requirement: Supported Keepiq versions
The extension SHALL use the send routes without a version gate, because `GET /api/v1/sends`, `POST /api/v1/sends`, `DELETE /api/v1/sends/{id}` and every field used exist since Keepiq 0.1.4, and the path-form recipient link `/public/send/<token>` since 0.3.0, both older than `MIN_KEEPIQ_VERSION` 0.3.12. No older-Keepiq scenario applies.

#### Scenario: Oldest supported Keepiq
- **GIVEN** the account's Keepiq is `MIN_KEEPIQ_VERSION`
- **WHEN** the user lists, creates and removes sends
- **THEN** every request is sent without a version check and succeeds

### Requirement: No persistence of send secrets
The extension MUST NOT write plaintext content, usernames, passwords, send passwords, content keys or share links to `storage.local`, `storage.session`, IndexedDB or any other persistent store. Plaintext and the send password live in popup memory (form) and in background memory during one `send.create` call; the content key lives in background memory during that call only; a link without a password lives in popup memory until the popup closes, the vault locks or the account changes; the sends list (server metadata and password-send links) lives in popup memory for as long.

#### Scenario: Storage after a create
- **GIVEN** a send was created with and without a password
- **WHEN** `storage.local` and `storage.session` are inspected
- **THEN** neither contains any key under the send module and no value contains the content, the link or the fragment

#### Scenario: Worker restart
- **GIVEN** the background worker restarts after a create
- **WHEN** it wakes
- **THEN** it holds no send state and the popup's copy of a link without a password is the only remaining copy
