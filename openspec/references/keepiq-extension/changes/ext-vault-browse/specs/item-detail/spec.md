## ADDED Requirements

### Requirement: Detail view opens from a card and returns to the list
Activating an item card or "View" SHALL open the item detail view in the content area with a back control. Returning SHALL restore the list with its search, folder, type filters and scroll position from popup memory.

#### Scenario: Open and return
- **GIVEN** the list is filtered to TOTP and scrolled halfway
- **WHEN** the user opens an item and then activates back
- **THEN** the list shows the TOTP filter and the same scroll position

### Requirement: Detail header
The detail header SHALL show the item `name`, the type label resolved from the snapshot's `types`, and the folder path built from `parentId` links joined with " / ", or "No folder". The header renders from metadata before any decryption.

#### Scenario: Nested folder
- **GIVEN** an item in folder "Clients" whose parent is "Work"
- **WHEN** the detail opens
- **THEN** the header shows the name, the type label and "Work / Clients"

### Requirement: Login credentials section
For items with a `login` or a `key` (types other than totp, card, identity, passkey and note) the detail SHALL show a "Login credentials" section with Username (value, copy) when `login` is present and Password (masked by default, reveal toggle, copy). Values are requested through `item.decrypt` when the view opens and held in popup memory.

#### Scenario: Reveal password
- **GIVEN** a login item open in detail
- **WHEN** the user activates the reveal toggle
- **THEN** the password is shown in a monospace field and the toggle flips to hide

#### Scenario: Item without a username
- **GIVEN** an `api_key` item with `login` null
- **WHEN** the detail opens
- **THEN** the section shows only the masked value labelled "API key" with reveal and copy

### Requirement: TOTP code with countdown
For items of type totp the detail SHALL parse the decrypted `key` as an `otpauth://totp/...` URI or a bare base32 secret (defaults SHA1, 6 digits, 30 seconds; SHA256 and SHA512 and 8 digits accepted), compute the current code client-side per RFC 6238 with WebCrypto, show it grouped in two halves with a countdown to the next period, refresh it at the period boundary and offer copy. An unparseable seed SHALL show "Invalid authenticator key" and no code. The seed, derived HMAC key, code and timers live in popup memory and are discarded when the view closes or the vault locks.

#### Scenario: Valid otpauth seed
- **GIVEN** a totp item whose key is `otpauth://totp/Example:alice?secret=<base32>&digits=6&period=30`
- **WHEN** the detail opens
- **THEN** a 6 digit code and a countdown are shown, and the code changes when the countdown reaches zero

#### Scenario: Invalid seed
- **GIVEN** a totp item whose key decrypts to `not a seed`
- **WHEN** the detail opens
- **THEN** "Invalid authenticator key" is shown and no code is displayed or copyable

### Requirement: Websites section
When the item has a `url` the detail SHALL show a "Website" entry with launch and copy actions using the same launch rules as the list card. Bitwarden's multiple URIs collapse to Keepiq's single `url`.

#### Scenario: Launch from detail
- **GIVEN** an item with url `https://example.com`
- **WHEN** the user activates launch
- **THEN** a new tab opens the url

### Requirement: Additional fields section
When `additionalFields` decrypts to a non-empty JSON object the detail SHALL list each entry as a name and value row, values masked by default with a reveal toggle and copy. Entries whose name is `notes` (case-insensitive) are rendered in the Notes section instead. If `additionalFields` does not parse as a JSON object the section shows "Could not read additional fields".

#### Scenario: Two custom fields
- **GIVEN** `additionalFields` decrypts to `{"Recovery code":"<value>","PIN":"<value>"}`
- **WHEN** the detail opens
- **THEN** two rows appear with masked values, each with reveal and copy

### Requirement: Notes section
The detail SHALL show a "Notes" section with multi-line, unmasked text when the item is of type note (the decrypted `key`) or when an additional field named `notes` exists. The section is omitted otherwise.

#### Scenario: Secure note
- **GIVEN** a note item whose key decrypts to three lines of text
- **WHEN** the detail opens
- **THEN** the Notes section shows the text with line breaks preserved and no Login credentials section is shown

### Requirement: Card and identity sections
For type card the detail SHALL parse the decrypted `key` as `{number, expiry, cvv, pin, cardholder}` and render Cardholder and Expiry in plain text, Number, CVV and PIN masked with reveal and copy, and show the brand and last four digits derived in memory from the number. For type identity it SHALL parse `{firstName, lastName, address, phone, email, bsn}` and render all fields in plain text except BSN, which is masked with reveal and copy. A payload that does not parse shows "Could not read this item" and no fields.

#### Scenario: Card detail
- **GIVEN** a card item with a Visa number ending 1111
- **WHEN** the detail opens
- **THEN** the number shows as `•••• •••• •••• 1111` with "Visa" until revealed, and CVV and PIN are masked

#### Scenario: Identity detail
- **GIVEN** an identity item with a BSN
- **WHEN** the detail opens
- **THEN** name, address, phone and email are readable and BSN is masked

### Requirement: Passkey section
For type passkey the detail SHALL parse the decrypted `key` and show the relying party (`rpName` with `rpId`, or `rpId` alone), the user name (`userName`, falling back to `userDisplayName`) and the credential's `createdAt`, together with the note "Signing in with this passkey is not yet supported in the extension". The private key MUST NOT be rendered or copyable. An unparseable payload shows "Could not read this passkey".

#### Scenario: Passkey detail
- **GIVEN** a passkey item for `example.com`
- **WHEN** the detail opens
- **THEN** relying party, user name and creation date are shown with the not-yet-supported note
- **AND** no private key field exists in the DOM

### Requirement: Blocked item detail
For a row with `blocked: true` the detail SHALL show the header, the `blockedReason` text, the `migrationError` when present, a link to open the Keepiq web app, and no value sections, copy actions or decrypt requests.

#### Scenario: Open a blocked item
- **GIVEN** a blocked row with reason "suite revoked"
- **WHEN** the detail opens
- **THEN** the reason and the web app link are shown and `item.decrypt` is never sent

### Requirement: Metadata section
The detail SHALL show "Created" and "Updated" from `createdAt` and `updatedAt` as absolute local date-times, and "Expires" from `expiresAt` when set.

#### Scenario: Metadata rendered
- **GIVEN** an item created on 2026-01-05 and updated on 2026-03-02
- **WHEN** the detail opens
- **THEN** both dates appear in the metadata section in the user's locale

### Requirement: Edit and Delete placeholders
The detail SHALL show Edit and Delete buttons at the bottom, disabled with the tooltip "Available in a later update" until ext-vault-edit enables them. For blocked items they stay disabled after ext-vault-edit as well.

#### Scenario: Buttons before ext-vault-edit
- **GIVEN** ext-vault-edit is not implemented
- **WHEN** the detail opens
- **THEN** Edit and Delete are visible and disabled with the tooltip

### Requirement: Decrypted values are dropped on close
The detail view MUST keep decrypted values, parsed payloads, TOTP state and reveal state in popup memory only, MUST clear them when the view closes, the popup closes or the vault locks, and MUST NOT persist them anywhere.

#### Scenario: Back to list
- **GIVEN** a detail view with a revealed password and a running TOTP timer
- **WHEN** the user activates back
- **THEN** the timer is stopped and the decrypted values are released
- **AND** reopening the same item requests `item.decrypt` again
