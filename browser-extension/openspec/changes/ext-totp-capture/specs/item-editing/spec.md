## MODIFIED Requirements

### Requirement: Add item form
The extension SHALL offer a primary "New" button with a plus icon in the header of the Vault tab's list, left of the pop-out button as in Bitwarden, that opens an add-item form with a Type select, a required Name and the fields of the chosen type, as in Bitwarden's "Add item". The Type select MUST list the cached `secret-types` rows except `passkey`, which only a passkey ceremony creates, and `totp`, since a login holds its own authenticator key (keepiq#1324), and default to the account's Default item type from the extension's settings (ext-settings) when the cached types hold it, else `login`. An item of either type keeps its type in the select while it is edited. For the `login` type the form MUST show Username, Password (masked, with a reveal button; ext-generator adds the generate button), Website URL, Authenticator key (ext-totp-capture), Folder (default "No folder"), Additional fields and Notes. A multi-line secret, such as an `ssh_key`, a `certificate` or a password holding a line break, MUST stay masked until the user presses Show, and stay multi-line once it holds a line break, also after the type changes; an edit MUST keep its CRLF or CR line breaks when it used one style throughout. Every other type except `passkey` MUST show Username when the edited item has a login, as the Keepiq web app does, and keep it while the form is open; cleared, it is saved as `null`. The draft lives in popup memory only and is cleared when the popup closes.

#### Scenario: Opening the form from a tab
- **GIVEN** the vault is unlocked and the user opened the popup on a tab whose URL is `https://example.com/login`
- **WHEN** the user presses New and keeps the default type `login`
- **THEN** the form MUST prefill Website URL with `https://example.com/login`
- **AND** Folder MUST read "No folder" and every other field MUST be empty

#### Scenario: Tab URL with a token
- **GIVEN** the tab's URL is `https://app.example.com/callback?token=abc#access_token=xyz`
- **WHEN** the user presses New
- **THEN** Website URL MUST read `https://app.example.com/callback`, since the URL is stored unencrypted

#### Scenario: Default type from the extension's settings
- **GIVEN** the account's Default item type is `note` and the cached types hold `note`
- **WHEN** the add-item form opens
- **THEN** Type MUST be preselected to `note` and the form MUST show the `note` fields

#### Scenario: Name is required
- **GIVEN** the add-item form is open with an empty Name
- **WHEN** the user presses Save
- **THEN** Save MUST be refused and the Name field MUST show "Name is required"
- **AND** no request MUST be sent

#### Scenario: No new Authenticator items
- **GIVEN** the cached types hold `totp`
- **WHEN** the user presses New and opens the Type select
- **THEN** Authenticator MUST NOT be listed
- **AND** the edit form of an existing Authenticator item MUST still show its type and its Secret field

### Requirement: Clone
The extension SHALL offer Clone on item detail, which MUST fresh-fetch and decrypt the item and open the add-item form prefilled with every field except the authenticator key (ext-totp-capture) and the Name suffixed with " - Clone", as in Bitwarden. Until the user picks another type, the form MUST render under the type name the fetch returned, as Edit does. Saving MUST create a new item and leave the original untouched.

#### Scenario: Clone a login
- **GIVEN** the detail view of "GitHub" is open
- **WHEN** the user presses Clone
- **THEN** the add-item form MUST open with Name "GitHub - Clone" and the original's type, username, password, URL, folder, notes and additional fields

#### Scenario: Clone a passkey
- **GIVEN** the detail view of a `passkey` item is open
- **WHEN** the user presses Clone
- **THEN** Clone MUST be unavailable with the hint "Passkeys cannot be cloned"
- **AND** the background MUST refuse a clone fetch of a row that the fresh fetch or the vault cache names a `passkey` with `passkey_clone`, before decrypting
