## ADDED Requirements

### Requirement: Vault tab layout
The Vault tab SHALL render, top to bottom: a search bar, a folder selector, a type filter row, the autofill suggestions section when applicable, and the item list. All of it renders from the `vault.snapshot` reply without any decryption.

#### Scenario: Unlocked vault with items
- **GIVEN** a cached snapshot with items and folders
- **WHEN** the Vault tab renders
- **THEN** search, folder selector, type chips and the list appear in that order
- **AND** the list shows every item before any decryption completes

### Requirement: Search over name and URL
The search bar SHALL filter the list client-side to items whose plaintext `name` or `url` contains the query, case-insensitive, over the cached snapshot. Search composes with the folder and type filters. An empty query shows all items in the current filters.

#### Scenario: Match on URL
- **GIVEN** an item named "Work mail" with url `https://mail.example.com`
- **WHEN** the user types `example`
- **THEN** the item is listed

#### Scenario: Username is not searched
- **GIVEN** an item whose decrypted login is `alice` and whose name and url do not contain `alice`
- **WHEN** the user types `alice`
- **THEN** the item is not listed and the no-matches state explains that search covers names and websites

### Requirement: Folder selector
The folder selector SHALL be a dropdown whose first entry is "All items", followed by the folder tree flattened depth-first with children sorted by name and indented per level, and ending with "No folder". Selecting a folder shows the items whose `folderId` equals that folder only, not its descendants, matching Bitwarden. "No folder" shows items with `folderId` null.

#### Scenario: Nested folders
- **GIVEN** folders "Work" with child "Clients"
- **WHEN** the dropdown opens
- **THEN** the entries read: All items, Work, (indented) Clients, No folder

#### Scenario: Select a parent folder
- **GIVEN** items in "Work" and in "Work / Clients"
- **WHEN** the user selects "Work"
- **THEN** only items with `folderId` equal to "Work" are listed

### Requirement: Type filter chips
The type filter SHALL show the chips All, Login, Card, Identity, Note, TOTP, Passkey. Any other type present in the snapshot's `types` (system types `api_key`, `ssh_key`, `certificate`, `database` and user or admin types) SHALL be reachable through a "More" chip that opens a menu of the remaining type labels. Exactly one type chip is active at a time and the choice composes with folder and search. Chips resolve `typeId` through the snapshot's `types` by `name`.

#### Scenario: Filter to TOTP
- **GIVEN** items of type login and totp
- **WHEN** the user activates the TOTP chip
- **THEN** only totp items are listed and the chip is shown active

#### Scenario: Custom type via More
- **GIVEN** an admin type labelled "Licence key"
- **WHEN** the user opens More and picks "Licence key"
- **THEN** only items of that type are listed and the More chip shows the label "Licence key"

### Requirement: Autofill suggestions section
When the popup has an active tab with an `http:` or `https:` URL, the Vault tab SHALL show an "Autofill suggestions" section above the item list, listing items whose `url` matches the tab by base domain as decided in the background (`src/vault/match.ts`). Cards in this section behave like list cards and open the detail view; the Fill action arrives with ext-autofill. When the tab has a matchable URL but no item matches, the section shows "No items for <host>". The section is hidden when the tab has no matchable URL, and it ignores the folder, type and search filters.

#### Scenario: Matching items
- **GIVEN** the active tab is `https://login.example.co.uk/x` and two items have urls under `example.co.uk`
- **WHEN** the Vault tab renders
- **THEN** both items appear under "Autofill suggestions" and also in the main list

#### Scenario: No matching items
- **GIVEN** the active tab is `https://nothing.test`
- **WHEN** the Vault tab renders
- **THEN** the section shows "No items for nothing.test"

#### Scenario: Blocked match
- **GIVEN** a matching item is blocked
- **WHEN** the section renders
- **THEN** the item appears with its blocked badge and no copy actions

### Requirement: Item card
Each item card SHALL show a type icon (globe for login, card for card, id card for identity, note for note, clock for totp, key for passkey, code for api_key, terminal for ssh_key, shield for certificate, database for database, generic key for anything else), the plaintext `name`, and a grey subtitle. The subtitle is the decrypted `login` when the row has one, otherwise the type label. Logins are decrypted lazily for rendered rows only through `item.decrypt`, held in popup memory and dropped when the popup closes. Blocked rows show a blocked badge instead of a subtitle. Website icons are not fetched.

#### Scenario: Login with username
- **GIVEN** a login item whose `login` decrypts to `alice@example.com`
- **WHEN** the card renders
- **THEN** the globe icon, the name and the subtitle `alice@example.com` are shown

#### Scenario: Note without login
- **GIVEN** a note item with `login` null
- **WHEN** the card renders
- **THEN** the subtitle reads "Note"

### Requirement: Launch action
Each card SHALL offer a Launch action when the item has a non-empty `url`, opening it in a new tab with `browser.tabs.create`. A url without a scheme is opened as `https://<url>`. Launch is hidden when `url` is empty.

#### Scenario: Launch a website
- **GIVEN** an item with url `github.com`
- **WHEN** the user activates Launch
- **THEN** a new tab opens `https://github.com`

### Requirement: Copy menu
Each card SHALL offer a Copy menu with "Copy username" (when `login` is present), "Copy password" (the decrypted `key`, for types other than totp, card, identity and passkey) and "Copy verification code" (the current TOTP code, for the totp type). Each entry decrypts on demand through `item.decrypt`, writes to the clipboard with `navigator.clipboard.writeText` in the popup, shows a "Copied" toast, and sends `clipboard.copied` to the background so the clipboard-clear alarm starts when a clear delay is configured. The menu is disabled on blocked rows.

#### Scenario: Copy password
- **GIVEN** an unlocked vault and a login item
- **WHEN** the user picks "Copy password"
- **THEN** the decrypted `key` is on the clipboard and a toast reads "Password copied"
- **AND** the background received `clipboard.copied`

#### Scenario: Copy verification code
- **GIVEN** a totp item with a valid seed
- **WHEN** the user picks "Copy verification code"
- **THEN** the current code is on the clipboard

#### Scenario: Clipboard write refused
- **GIVEN** the browser denies the clipboard write
- **WHEN** the user copies
- **THEN** a toast reads "Could not copy" and nothing else changes

### Requirement: More menu
Each card SHALL offer a More menu with View, Edit, Clone, Move to folder and Delete. View opens the detail view. Edit, Clone, Move to folder and Delete are shown disabled with the tooltip "Available in a later update" until ext-vault-edit wires them.

#### Scenario: Open More before ext-vault-edit
- **GIVEN** ext-vault-edit is not implemented
- **WHEN** the user opens the More menu
- **THEN** View is enabled and Edit, Clone, Move to folder, Delete are disabled with the tooltip

### Requirement: Alphabetical sorting
The list and the autofill suggestions SHALL be sorted by `name` using locale-aware, case-insensitive comparison, with ties broken by `id` so the order is stable across renders.

#### Scenario: Mixed case names
- **GIVEN** items named "bank", "Amazon" and "apple"
- **WHEN** the list renders
- **THEN** the order is Amazon, apple, bank

### Requirement: Empty, loading and blocked states
The Vault tab SHALL show: "Syncing your vault" with a spinner while the first sync for an account is running and no snapshot exists; "No items in your vault" with a link to open the Keepiq web app when the snapshot is empty; "No items match" with a "Clear filters" action when filters or search exclude everything; and "All items are blocked" with the shared `blockedReason` and a link to the web app when every row is blocked.

#### Scenario: First sync in progress
- **GIVEN** a freshly unlocked account with no snapshot
- **WHEN** the Vault tab renders
- **THEN** the syncing state is shown and no filters are interactive

#### Scenario: Filters exclude everything
- **GIVEN** the Card chip is active and the vault has no cards
- **WHEN** the list renders
- **THEN** "No items match" and "Clear filters" appear
- **AND** activating "Clear filters" resets the type chip to All, the folder to All items and empties the search

#### Scenario: Blocked-only vault
- **GIVEN** every row is blocked with reason "suite revoked"
- **WHEN** the list renders
- **THEN** the blocked-only state is shown above the list of blocked cards

### Requirement: Decrypted values live in popup memory only
The Vault tab MUST hold decrypted logins, passwords and codes in popup memory only, MUST drop them when the popup closes or the vault locks, and MUST NOT write them to `storage.local`, `storage.session` or any DOM attribute that outlives the render.

#### Scenario: Lock while the list is open
- **GIVEN** 20 subtitles are decrypted and shown
- **WHEN** the vault locks
- **THEN** the popup swaps to the unlock view and the in-memory map of decrypted values is cleared
