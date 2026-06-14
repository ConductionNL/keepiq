## ADDED Requirements

### Requirement: Create Folder
The system MUST allow an authenticated user to create a folder with a name and an optional parent folder. Folder names MUST be single path segments (no slashes). The owner_type and owner_id MUST be set to the creating user. If a parent_id is specified, the parent folder MUST be owned by the same user.

#### Scenario: Create root-level folder
- **WHEN** a user creates a folder with a name and no parent_id
- **THEN** the system MUST create the folder with parent_id null, owner_type `user`, and owner_id set to the user's UID

#### Scenario: Create subfolder
- **WHEN** a user creates a folder with a name and a parent_id referencing a folder they own
- **THEN** the system MUST create the folder with the specified parent_id

#### Scenario: Create folder with invalid parent
- **WHEN** a user creates a folder with a parent_id referencing a folder they do not own
- **THEN** the system MUST return a 403 error

#### Scenario: Create folder with slash in name rejected
- **WHEN** a user creates a folder with a name containing a slash character
- **THEN** the system MUST return a 400 error indicating folder names cannot contain slashes

### Requirement: Rename Folder
The system MUST allow a user to rename a folder they own. Renaming MUST NOT affect any secrets or subfolders within the folder.

#### Scenario: Rename folder
- **WHEN** a user renames a folder they own
- **THEN** the system MUST update the folder name and set updated_at
- **AND** all secrets and subfolders within it MUST remain unaffected

### Requirement: Move Folder
The system MUST allow a user to move a folder to a different parent (or to root). All secrets and subfolders within the moved folder MUST move with it implicitly (because their parent_id chains are preserved).

#### Scenario: Move folder to different parent
- **WHEN** a user moves a folder to a different parent folder they own
- **THEN** the system MUST update the folder's parent_id to the new parent

#### Scenario: Move folder to root
- **WHEN** a user moves a folder to root (parent_id = null)
- **THEN** the system MUST set the folder's parent_id to null

#### Scenario: Move folder to non-owned parent rejected
- **WHEN** a user attempts to move a folder to a parent they do not own
- **THEN** the system MUST return a 403 error

### Requirement: Delete Empty Folder
The system MUST allow a user to delete a folder that contains no secrets and no subfolders.

#### Scenario: Delete empty folder
- **WHEN** a user deletes a folder that has no secrets and no subfolders
- **THEN** the folder MUST be removed from the database

### Requirement: Delete Non-Empty Folder Without Subfolders
When a folder contains secrets but no subfolders, the system MUST support deletion via query parameter cascade modes. Without a cascade parameter, the system MUST return 409 Conflict. With `?cascade=delete`, the folder and all its direct secrets MUST be deleted. With `?cascade=move`, all direct secrets MUST be moved to the folder's parent (or root) and the folder MUST be deleted.

#### Scenario: Delete non-empty folder without cascade parameter
- **WHEN** a user deletes a folder that contains secrets but no subfolders, without a cascade parameter
- **THEN** the system MUST return 409 Conflict with message "Folder is not empty"

#### Scenario: Delete folder with cascade=delete
- **WHEN** a user deletes a folder (no subfolders) with `?cascade=delete`
- **THEN** the folder and all its direct secrets MUST be deleted

#### Scenario: Delete folder with cascade=move
- **WHEN** a user deletes a folder (no subfolders) with `?cascade=move`
- **THEN** all direct secrets MUST be moved to the folder's parent (or root if no parent)
- **AND** the folder MUST be deleted

### Requirement: Delete Folder With Subfolders -- Resolution Required
When a folder contains subfolders, the system MUST require a resolution plan in the DELETE request body. The query parameter shorthand (`?cascade=delete` or `?cascade=move`) alone MUST return 409 Conflict with message "Folder contains subfolders -- resolution required". The resolution body MUST include an entry for every direct subfolder.

#### Scenario: Delete folder with subfolders using only cascade parameter
- **WHEN** a user sends DELETE with `?cascade=delete` but no body, and the folder has subfolders
- **THEN** the system MUST return 409 Conflict with "Folder contains subfolders -- resolution required"

#### Scenario: Delete folder with resolution body
- **WHEN** a user sends DELETE with a JSON body containing `directSecrets` action and a `subfolders` map with an entry for every direct subfolder
- **THEN** the system MUST process the resolution plan and delete the folder

#### Scenario: Resolution body missing a subfolder entry
- **WHEN** a user sends DELETE with a resolution body that is missing an entry for one or more direct subfolders
- **THEN** the system MUST return 400 Bad Request

#### Scenario: Subfolder action delete
- **WHEN** a resolution entry maps a subfolder to `delete`
- **THEN** the system MUST recursively delete the subfolder, all its nested subfolders, and all secrets in the entire subtree (depth-first)

#### Scenario: Subfolder action move
- **WHEN** a resolution entry maps a subfolder to `move`
- **THEN** the system MUST recursively collect all secrets from the subfolder's entire subtree, move them to the deleted folder's parent (or root), and delete the subfolder and all nested subfolders

#### Scenario: Subfolder action keep
- **WHEN** a resolution entry maps a subfolder to `keep`
- **THEN** the system MUST re-parent the subfolder to the deleted folder's parent (or root)
- **AND** all contents within the subfolder MUST remain unchanged

### Requirement: List Folder Children
The system MUST provide a `GET /folders/{id}/children` endpoint that returns the folder's direct secret count and an array of direct subfolders with their recursive secret counts and subfolder counts. This endpoint is used by the frontend to build the subfolder resolution dialog.

#### Scenario: List children of a folder with subfolders
- **WHEN** a user calls GET /folders/{id}/children on a folder with 2 direct secrets and 1 subfolder (containing 3 secrets and 1 nested subfolder)
- **THEN** the system MUST return `directSecretCount: 2` and a subfolders array with one entry showing `secretCount: 3, subfolderCount: 1`

#### Scenario: List children of a leaf folder
- **WHEN** a user calls GET /folders/{id}/children on a folder with no subfolders and 5 secrets
- **THEN** the system MUST return `directSecretCount: 5` and an empty subfolders array

#### Scenario: List children of non-owned folder rejected
- **WHEN** a user calls GET /folders/{id}/children on a folder they do not own
- **THEN** the system MUST return a 403 error

### Requirement: Folder Path Derivation
Folder paths MUST be derived at query time by traversing parent_id links. Path strings (e.g., `personal/email/work`) MUST NOT be stored in the database. The system MUST compute the full path using a recursive query when displaying folder paths in the UI.

#### Scenario: Derive path for nested folder
- **WHEN** the system needs to display the path for a folder named "work" whose parent is "email" whose parent is "personal" (root)
- **THEN** the derived path MUST be "personal/email/work"

#### Scenario: Derive path for root-level folder
- **WHEN** the system needs to display the path for a root-level folder named "personal"
- **THEN** the derived path MUST be "personal"

### Requirement: Folder Ownership Isolation
Each user's folder tree MUST be independent. A user MUST NOT be able to see, modify, or delete another user's folders. Received shares are placed in the recipient's own folder structure -- the owner's folder organization is not visible to the recipient.

#### Scenario: List only own folders
- **WHEN** a user lists their folders
- **THEN** the system MUST return only folders where owner_type is `user` and owner_id matches the user's UID

#### Scenario: Access another user's folder rejected
- **WHEN** a user attempts to access a folder owned by a different user
- **THEN** the system MUST return a 403 error
