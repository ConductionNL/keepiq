## ADDED Requirements

### Requirement: Add item form
The extension SHALL offer a "+" button at the bottom right of the Vault tab list that opens an add-item form with a Type select, a required Name and the fields of the chosen type, as in Bitwarden's "Add item". The Type select MUST list the cached `secret-types` rows and default to the user's `default_secret_type` when cached, else `login`. For the `login` type the form MUST show Username, Password (masked, with reveal and generate buttons), Website URL, Folder (default "No folder"), Additional fields and Notes. The draft lives in popup memory only and is cleared when the popup closes.

#### Scenario: Opening the form from a tab
- **GIVEN** the vault is unlocked and the user opened the popup on a tab whose URL is `https://example.com/login`
- **WHEN** the user presses "+" and keeps the default type `login`
- **THEN** the form MUST prefill Website URL with `https://example.com/login`
- **AND** Folder MUST read "No folder" and every other field MUST be empty

#### Scenario: Default type from user settings
- **GIVEN** the cached user settings carry `default_secret_type` = `note`
- **WHEN** the add-item form opens
- **THEN** Type MUST be preselected to `note` and the form MUST show the `note` fields

#### Scenario: Name is required
- **GIVEN** the add-item form is open with an empty Name
- **WHEN** the user presses Save
- **THEN** Save MUST be refused and the Name field MUST show "Name is required"
- **AND** no request MUST be sent

#### Scenario: Generate a password
- **GIVEN** the add-item form is open for a `login` item
- **WHEN** the user presses the generate button next to Password
- **THEN** the popup MUST open the generator in "pick a password" mode
- **AND** returning from the generator MUST place the picked value in Password and keep every other field the user filled

### Requirement: Additional fields editor
The extension SHALL let the user add, edit and remove repeatable name and value rows under Additional fields, with a masking toggle per row. A row name that equals `key`, `login` or `url` case-insensitively MUST be refused with the reason "This name is reserved", a blank name MUST be refused, and a duplicate name MUST be refused. The masking toggle is view state only and is not stored, because the Keepiq blob has no field type.

#### Scenario: Reserved name is refused
- **GIVEN** the user adds an additional field
- **WHEN** they type the name `Login`
- **THEN** the row MUST show "This name is reserved" and Save MUST be disabled until the name changes

#### Scenario: Duplicate name is refused
- **GIVEN** an additional field named `pin` exists on the form
- **WHEN** the user names a second row `pin`
- **THEN** the second row MUST show "A field with this name already exists" and Save MUST be disabled

#### Scenario: Masked value can be revealed
- **GIVEN** an additional field row is masked
- **WHEN** the user presses the row's reveal toggle
- **THEN** the value MUST be shown in plain text until the toggle is pressed again or the form closes

### Requirement: Notes storage convention
The extension SHALL store the Notes field of a non-`note` item under the `notes` member of `additionalFields`, and MUST store the text of a `note` item in `key`. Notes MUST never be sent as plaintext.

#### Scenario: Notes on a login item
- **GIVEN** the user fills Notes on a `login` item and adds no additional field
- **WHEN** they save
- **THEN** the encrypted `additionalFields` blob MUST decrypt to `{"notes": "<text>"}`
- **AND** the Additional fields editor MUST NOT show `notes` as a row when the item is edited later

#### Scenario: Note item
- **GIVEN** the user chose the `note` type and typed a note
- **WHEN** they save
- **THEN** the note text MUST be encrypted into `key` and `login` MUST be omitted

### Requirement: Composite types
The extension SHALL edit `totp` items as a Secret field holding an `otpauth://totp/...` URI or a bare base32 secret in `key`, with optional advanced fields (algorithm, digits, period) that are written back into the URI. `card` items MUST store `{number, expiry, cvv, pin, cardholder}` and `identity` items MUST store `{firstName, lastName, address, phone, email, bsn}` as JSON in `key`, serialized exactly as the Keepiq web app does, so the web app renders them. `passkey` items MUST be editable only in Name, Folder and Website URL; the form MUST never read, show or send the credential JSON in `key`.

#### Scenario: TOTP with a bare secret
- **GIVEN** the user chose `totp` and typed `JBSWY3DPEHPK3PXP` as the Secret
- **WHEN** they save without touching the advanced fields
- **THEN** `key` MUST encrypt the bare secret unchanged

#### Scenario: Invalid TOTP secret
- **GIVEN** the user typed `not a secret!` as the TOTP Secret
- **WHEN** they press Save
- **THEN** Save MUST be refused with "Not a valid authenticator secret" and no request MUST be sent

#### Scenario: Card payload
- **GIVEN** the user chose `card` and filled number, expiry and cardholder
- **WHEN** they save
- **THEN** `key` MUST decrypt to a JSON object with exactly the five card members, `cvv` and `pin` as empty strings

#### Scenario: Passkey stays read-only
- **GIVEN** the user opens Edit on a `passkey` item
- **WHEN** the form renders
- **THEN** it MUST show Name, Folder and Website URL as editable and a notice "Passkey credentials can only be changed in the Keepiq web app"
- **AND** saving MUST send a sparse `PUT` without `key`

### Requirement: Create sends ciphertext and opens the new item
The extension SHALL save a new item by sending the draft to the background, which MUST encrypt `key`, `login` and the `additionalFields` JSON object with the active suite certificate per ADR-003 and call `POST /api/v1/secrets` with plaintext `name`, `url`, `typeId` and `folderId`. The popup MUST never encrypt. On success the background MUST run the sync from ext-vault-browse and the popup MUST open the new item's detail view.

#### Scenario: Successful create
- **GIVEN** a valid `login` draft
- **WHEN** the user presses Save
- **THEN** the request body MUST contain `key`, `login` and `additionalFields` as base64 RSA blobs and `name`, `url`, `typeId`, `folderId` as plaintext
- **AND** after the 201 response the vault list MUST contain the item and its detail view MUST be open

#### Scenario: Empty optional fields
- **GIVEN** a `login` draft with an empty Username, no URL and no notes or additional fields
- **WHEN** the user presses Save
- **THEN** the body MUST omit `login`, MUST set `url` to `null` and MUST encrypt `{}` as `additionalFields`

### Requirement: Edit re-fetches before showing the form and saves sparsely
The extension SHALL, when the user presses Edit, call `GET /api/v1/secrets/{id}` and decrypt the response in the background instead of using the cached row, so a stale cache cannot overwrite a newer server value. The decrypted item lives in popup memory only and is cleared when the form closes. Save MUST send `PUT /api/v1/secrets/{id}` with only the fields the user changed; a changed `key` or `login` MUST be re-encrypted, a cleared `login`, `url` or `folderId` MUST be sent as `null`, and any change to notes or additional fields MUST re-send the whole `additionalFields` object built from the fresh fetch, since the server stores it as one blob.

#### Scenario: Rename only
- **GIVEN** the user opened Edit and changed only Name
- **WHEN** they save
- **THEN** the `PUT` body MUST contain only `name`
- **AND** no ciphertext MUST be re-encrypted or sent

#### Scenario: Stale cache
- **GIVEN** the cached row holds an older `login` than the server
- **WHEN** the user presses Edit
- **THEN** the form MUST show the server's decrypted `login`

#### Scenario: Clear a field
- **GIVEN** the user opened Edit and emptied Username
- **WHEN** they save
- **THEN** the `PUT` body MUST contain `"login": null`

#### Scenario: Item deleted elsewhere
- **GIVEN** the item was deleted in the web app after the last sync
- **WHEN** the user presses Edit
- **THEN** the fresh fetch returns 404 and the popup MUST show "This item no longer exists", run sync and return to the list

#### Scenario: Blocked item
- **GIVEN** the fresh fetch returns a row with `blocked: true`
- **WHEN** the form would open
- **THEN** the popup MUST refuse to edit and show the blocked reason as ext-vault-browse does for detail

### Requirement: Delete with confirmation
The extension SHALL delete an item only after the user confirms an in-popup dialog "Delete <name>? This cannot be undone." and MUST then call `DELETE /api/v1/secrets/{id}`, run sync and return to the list. There is no trash.

#### Scenario: Confirmed delete
- **GIVEN** the detail view of "GitHub" is open
- **WHEN** the user presses Delete and confirms
- **THEN** `DELETE /api/v1/secrets/{id}` MUST be sent and the list MUST no longer show "GitHub"

#### Scenario: Cancelled delete
- **GIVEN** the confirm dialog is open
- **WHEN** the user presses Cancel
- **THEN** no request MUST be sent and the detail view MUST stay open

### Requirement: Clone
The extension SHALL offer Clone on item detail, which MUST fresh-fetch and decrypt the item and open the add-item form prefilled with every field and the Name suffixed with " - Clone", as in Bitwarden. Saving MUST create a new item and leave the original untouched.

#### Scenario: Clone a login
- **GIVEN** the detail view of "GitHub" is open
- **WHEN** the user presses Clone
- **THEN** the add-item form MUST open with Name "GitHub - Clone" and the original's type, username, password, URL, folder, notes and additional fields

#### Scenario: Clone a passkey
- **GIVEN** the detail view of a `passkey` item is open
- **WHEN** the user presses Clone
- **THEN** Clone MUST be unavailable with the hint "Passkeys cannot be cloned"

### Requirement: Move to folder
The extension SHALL offer Move on item detail, which MUST open the folder picker and on selection send `PUT /api/v1/secrets/{id}` with only `folderId`, then run sync.

#### Scenario: Move into a folder
- **GIVEN** the detail view of an item without a folder is open
- **WHEN** the user picks "Work" in the folder picker
- **THEN** the `PUT` body MUST be `{"folderId": "<uuid>"}` and the detail view MUST show the folder "Work"

#### Scenario: Move to no folder
- **GIVEN** the item is in "Work"
- **WHEN** the user picks "No folder"
- **THEN** the `PUT` body MUST be `{"folderId": null}`

### Requirement: Input limits
The extension SHALL cap Name and Website URL at 4096 characters, every plaintext field and additional field value at 4096 characters, and the serialized `additionalFields` JSON and the `key` plaintext at 65536 UTF-8 bytes, mirroring the Keepiq import limits, and MUST refuse Save with a per-field message when a cap is exceeded.

#### Scenario: Oversized note
- **GIVEN** the user pasted 70000 bytes into Notes
- **WHEN** they press Save
- **THEN** Save MUST be refused and Notes MUST show "Too long (max 65536 bytes)"

### Requirement: Write errors keep the user's input
The extension SHALL map write failures to messages and MUST keep the form with the user's input intact after any failure. A 423 MUST show "Vault is temporarily locked for a key migration, try again later". A 403 MUST show "Your encryption suite is blocked, open Keepiq to resolve it". A 400 MUST show the server's `message`. A network failure MUST show "Could not reach the server" and offer Retry.

#### Scenario: Write lock
- **GIVEN** the user presses Save on a valid draft
- **WHEN** the server answers 423
- **THEN** the form MUST show the write-lock message and MUST still contain every value the user typed

#### Scenario: Network failure on delete
- **GIVEN** the user confirmed Delete
- **WHEN** the request fails without a response
- **THEN** the detail view MUST stay open with "Could not reach the server" and the item MUST remain in the list

### Requirement: Offline disables Save
The extension SHALL disable Save, Delete, Clone and Move while the background reports the server unreachable, with the explanation "You are offline. Changes need a connection to Keepiq." Reading and copying items stays available.

#### Scenario: Offline while editing
- **GIVEN** the edit form is open
- **WHEN** the background reports the last request failed with a network error
- **THEN** Save MUST be disabled and the explanation MUST be shown
- **AND** Save MUST be re-enabled when a later request succeeds

### Requirement: Unsaved changes warning
The extension SHALL warn "You have unsaved changes. Discard them?" when the user navigates away from a form with changes inside the popup, with Discard and Keep editing. Drafts are held in popup memory only and are lost when the popup closes; Bitwarden's pop-out draft is not kept.

#### Scenario: Navigating away
- **GIVEN** the user changed a field on the add-item form
- **WHEN** they press Back or switch tabs in the popup
- **THEN** the warning MUST appear and Keep editing MUST return to the form with the values intact

#### Scenario: No changes
- **GIVEN** the user opened the form and changed nothing
- **WHEN** they press Back
- **THEN** the popup MUST return without a warning
