## MODIFIED Requirements

### Requirement: Edit re-fetches before showing the form and saves sparsely
The extension SHALL, when the user presses Edit, call `GET /api/v1/secrets/{id}` and `GET /api/v1/secrets/{id}/write-context` and decrypt the response in the background instead of using the cached row, so a stale cache cannot overwrite a newer server value. The decrypted item lives in popup memory only and is cleared when the form closes. Save MUST send only the fields the user changed with `baseUpdatedAt`, the fresh fetch's `updatedAt`: an edit that changes `key`, `login` or `additionalFields` as the `patch` of `PUT /api/v1/secrets/{id}/share-save` with `baseUpdatedAt` in its envelope, as `share-sync` specifies, and any other edit as the body of `PUT /api/v1/secrets/{id}`. A changed `key` or `login` MUST be re-encrypted, a cleared `login`, `url` or `folderId` MUST be sent as `null`, and any change to notes or additional fields MUST re-send the whole `additionalFields` object built from the fresh fetch, since the server stores it as one blob. Type cannot be changed on edit, as in Bitwarden, and the form renders under the type name the fetch checked the item against, not the popup's cached one.

#### Scenario: Rename only
- **GIVEN** the user opened Edit and changed only Name
- **WHEN** they save
- **THEN** the `PUT /api/v1/secrets/{id}` body MUST contain only `name` and `baseUpdatedAt`
- **AND** no ciphertext MUST be re-encrypted or sent

#### Scenario: Changed elsewhere while editing
- **GIVEN** the user opened Edit and the item was changed in the web app before they saved
- **WHEN** they save
- **THEN** the server answers 409 and the form MUST keep the typed values and show "This item was changed elsewhere. Go back and edit it again to see the latest version."

#### Scenario: Stale cache
- **GIVEN** the cached row holds an older `login` than the server
- **WHEN** the user presses Edit
- **THEN** the form MUST show the server's decrypted `login`

#### Scenario: Clear a field
- **GIVEN** the user opened Edit and emptied Username
- **WHEN** they save
- **THEN** the share-save `patch` MUST contain `"login": null`

#### Scenario: Item deleted elsewhere
- **GIVEN** the item was deleted in the web app after the last sync
- **WHEN** the user presses Edit
- **THEN** the fresh fetch returns 404 and the popup MUST show "This item no longer exists", run sync and return to the list

#### Scenario: Blocked item
- **GIVEN** the item's suite was revoked after the last sync, so the server refuses the fresh fetch (ADR-003)
- **WHEN** the user presses Edit
- **THEN** the extension MUST run sync, which marks the item blocked, and the popup MUST refuse to edit and show the blocked reason as ext-vault-browse does for detail

### Requirement: Edits of shared items
An edit that changes `key`, `login` or `additionalFields` SHALL save the editor's row and every other copy in one `PUT /api/v1/secrets/{id}/share-save`, as `share-sync` specifies; any other edit saves through `PUT /api/v1/secrets/{id}`. A save either reaches everyone or writes nothing: a failure MUST keep the form open with the draft and the reason, and the user saves again. The `item.update` reply's value MUST carry `shareSave` when the item's detail has a note to show, as `share-sync` specifies, and a Move never does.

#### Scenario: Shared item edited
- **GIVEN** Alice shared "Wifi" with Bob
- **WHEN** Alice changes its password in the extension
- **THEN** Alice's copy and Bob's copy MUST hold the new password when the reply arrives
- **AND** the popup MUST show the item's detail with "Item saved" and no note

#### Scenario: Save fails
- **GIVEN** Alice shared "Wifi" with Bob and the server stops answering during the save
- **WHEN** she saves a new password
- **THEN** the form MUST stay open with her change and the offline message
- **AND** neither her copy nor Bob's MUST have changed

#### Scenario: Renamed shared item
- **GIVEN** Alice shared "Wifi" with Bob
- **WHEN** she renames it to "Home wifi"
- **THEN** the reply MUST carry no `shareSave` and Bob's copy keeps its own name

### Requirement: Shared copies keep their limits
The extension SHALL keep the limits Keepiq puts on a recipient's copy (Keepiq `use-only-shares`, `expiring-shares`, `federated-sharing`, `folder-permission-grades`), in the popup and again in the background:
- A use-only copy (`useOnly`) MUST NOT be edited, cloned or moved, and its value and additional fields MUST NOT be shown, copied or sent to the popup; only its login name may be (ext-vault-browse `item-detail`).
- A read-only copy from another organisation (`readOnly`) MUST NOT be edited or cloned; it may be moved.
- A copy without write permission, one whose `write-context` grade isn't `owner`, `write` or `manage`, MUST NOT be edited; it may be cloned and moved (`share-sync`).
- An expiring copy (`accessExpiresAt`) MUST NOT be cloned, since the clone would outlive the end date.
Each unavailable action shows why.

#### Scenario: Use-only copy in detail
- **GIVEN** Bob holds a use-only copy of "Supplier portal"
- **WHEN** he opens it
- **THEN** the detail MUST show the login name and the note "Its owner doesn't let you view or copy this.", with no password row, reveal or copy
- **AND** Edit, Clone and Move MUST be unavailable with "Shared with you for signing in only"

#### Scenario: Clone of an expiring copy
- **GIVEN** Bob's access to a copy ends on 1 December
- **WHEN** he opens the More menu
- **THEN** Clone MUST be unavailable with "Your access ends <date>, so it cannot be cloned"
- **AND** an `item.fetch` for a clone of it MUST be refused by the background

#### Scenario: View-only copy in detail
- **GIVEN** Bob holds a copy of "Router" shared with him directly, without a grade
- **WHEN** he opens it
- **THEN** Edit MUST be unavailable with "Shared with you to view only. Ask its owner for edit rights to change it."
- **AND** an `item.fetch` for an edit of it MUST be refused by the background
