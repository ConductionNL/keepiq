## ADDED Requirements

### Requirement: Folder manager location and tree
The extension SHALL show a folder manager at Settings → Vault → Folders, as in Bitwarden, rendering the cached folder rows from `storage.local` as a tree by `parentId`, sorted by name within each level, with an "Add folder" button. Folder rows are cached by the sync from ext-vault-browse and are cleared as ADR-002 states.

#### Scenario: Nested folders render as a tree
- **GIVEN** folders "Work" and "Work/Clients" where the second has `parentId` of the first
- **WHEN** the folder manager opens
- **THEN** "Clients" MUST render indented under "Work"

#### Scenario: No folders
- **GIVEN** the user has no folders
- **WHEN** the folder manager opens
- **THEN** it MUST show "No folders yet" and the "Add folder" button

### Requirement: Create folder
The extension SHALL create a folder from the folder manager and inline from the folder picker via a "New folder" entry, sending `POST /api/v1/folders` with `name` and `parentId` from the background and running sync on success. A blank name MUST be refused locally with "Name is required", a name containing `/` MUST be refused locally with "Folder names cannot contain slashes", and a 409 MUST show "A folder with this name already exists here".

#### Scenario: Create from the picker while adding an item
- **GIVEN** the add-item form is open and the user opened the folder picker
- **WHEN** they choose "New folder", type "Banking" and confirm
- **THEN** `POST /api/v1/folders` MUST be sent with `{"name": "Banking", "parentId": null}`
- **AND** the picker MUST select "Banking" and return to the form with the other fields intact

#### Scenario: Create a subfolder
- **GIVEN** the folder manager is open
- **WHEN** the user presses "Add folder" on the row "Work" and names it "Clients"
- **THEN** the request MUST carry `parentId` of "Work"

#### Scenario: Slash in name
- **GIVEN** the new-folder dialog is open
- **WHEN** the user types "Work/Clients"
- **THEN** the dialog MUST show "Folder names cannot contain slashes" and no request MUST be sent

#### Scenario: Duplicate sibling
- **GIVEN** a folder "Work" exists at the root
- **WHEN** the user creates another root folder named "Work"
- **THEN** the server answers 409 and the dialog MUST show "A folder with this name already exists here" with the typed name kept

### Requirement: Rename folder
The extension SHALL rename a folder from the folder manager by sending `PUT /api/v1/folders/{id}` with only `name`, then running sync. The same name rules as for create apply.

#### Scenario: Rename
- **GIVEN** the folder manager shows "Work"
- **WHEN** the user renames it to "Office" and confirms
- **THEN** the `PUT` body MUST be `{"name": "Office"}` and the tree MUST show "Office" with its subfolders unchanged

### Requirement: Delete empty folder
The extension SHALL delete a folder with no items and no subfolders after the confirm dialog "Delete <name>? This cannot be undone." by sending `DELETE /api/v1/folders/{id}` without a cascade, then running sync.

#### Scenario: Delete an empty folder
- **GIVEN** the folder "Old" holds no items and no subfolders in the cache
- **WHEN** the user presses Delete and confirms
- **THEN** `DELETE /api/v1/folders/{id}` MUST be sent without a query and "Old" MUST leave the tree

### Requirement: Delete a non-empty leaf folder
The extension SHALL, for a folder that holds items but no subfolders, ask "Delete <name> and its <n> items, or move them to <parent name or "No folder">?" with the choices "Delete items too" and "Move items to parent", mapping to `DELETE /api/v1/folders/{id}?cascade=delete` and `?cascade=move`. Bitwarden does not ask; Keepiq's delete protocol requires the choice.

#### Scenario: Move items to parent
- **GIVEN** the root folder "Old" holds 3 items and no subfolders
- **WHEN** the user presses Delete and chooses "Move items to parent"
- **THEN** the request MUST be `DELETE /api/v1/folders/{id}?cascade=move`
- **AND** after sync the 3 items MUST show "No folder"

#### Scenario: Delete items too
- **GIVEN** the same folder
- **WHEN** the user chooses "Delete items too"
- **THEN** the request MUST use `?cascade=delete` and the 3 items MUST leave the vault list after sync

### Requirement: Delete a folder with subfolders
The extension SHALL, for a folder with subfolders, call `GET /api/v1/folders/{id}/children` and show a resolution dialog with a choice for the folder's direct items (Delete or Move to parent) and, per direct subfolder with its item and subfolder counts, one of Delete, Move items to parent, or Keep (re-parent). The choice MUST be sent as the JSON body of `DELETE /api/v1/folders/{id}` in the shape the Keepiq secrets spec defines (`directSecrets` and a `subfolders` map covering every direct subfolder), defaulting every choice to Keep and Move to parent.

#### Scenario: Resolution dialog
- **GIVEN** "Work" holds 2 items and the subfolder "Clients" with 5 items and 1 nested subfolder
- **WHEN** the user presses Delete on "Work"
- **THEN** the dialog MUST show "2 items" for Work and a row "Clients, 5 items, 1 subfolder" with Delete, Move items to parent and Keep

#### Scenario: Keep a subfolder
- **GIVEN** the resolution dialog for "Work" is open with defaults
- **WHEN** the user confirms
- **THEN** the `DELETE` body MUST be `{"directSecrets": "move", "subfolders": {"<clients-uuid>": "keep"}}`
- **AND** after sync "Clients" MUST render at the root

#### Scenario: Server rejects an incomplete plan
- **GIVEN** a subfolder was created in the web app after the children call
- **WHEN** the server answers 400
- **THEN** the dialog MUST show the server's `message` and offer Retry, which re-fetches children

### Requirement: Folder picker
The extension SHALL provide a folder picker used by the add-item form, the edit form and Move, listing "No folder" first and then the folder tree with indentation, the current folder marked, and a "New folder" entry at the end. Selection MUST return the `folderId` (or `null`) to the caller without a request.

#### Scenario: Pick a nested folder
- **GIVEN** the picker is open from the edit form
- **WHEN** the user selects "Clients" under "Work"
- **THEN** the form's Folder field MUST read "Work / Clients" and hold the `folderId` of "Clients"

#### Scenario: Empty tree
- **GIVEN** the user has no folders
- **WHEN** the picker opens
- **THEN** it MUST list "No folder" and "New folder" only

### Requirement: Folder names are plaintext
The extension SHALL send and cache folder names as plaintext, as ADR-003 defines the folder rows. The folder manager MUST show the notice "Folder names are not encrypted" once per install, dismissible, stored as a flag in `storage.local` that account removal clears.

#### Scenario: First visit
- **GIVEN** the notice was never dismissed
- **WHEN** the folder manager opens
- **THEN** the notice MUST be shown with a Dismiss button

### Requirement: Folder write errors and offline
The extension SHALL map folder write failures as item writes do: 423 shows the write-lock message, 403 the suite-blocked message, 400 and 409 the server's `message`, a network failure "Could not reach the server" with Retry, and every dialog MUST keep the typed values. While the background reports the server unreachable, Add folder, Rename and Delete MUST be disabled with "You are offline. Changes need a connection to Keepiq."

#### Scenario: Write lock on rename
- **GIVEN** the user confirmed a rename
- **WHEN** the server answers 423
- **THEN** the rename dialog MUST stay open with the new name and show "Vault is temporarily locked for a key migration, try again later"

#### Scenario: Offline
- **GIVEN** the background reports the last request failed with a network error
- **WHEN** the folder manager opens
- **THEN** Add folder, Rename and Delete MUST be disabled with the offline explanation and the tree MUST still render from the cache
