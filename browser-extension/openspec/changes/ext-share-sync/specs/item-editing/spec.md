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
