# Secrets Specification

**Status**: in-progress

**OpenSpec changes:**
- `implement-secrets` (2026-03-31) — Full implementation: Secret/Folder/SecretType CRUD, search, unified search, list/pagination, favicon, clipboard

## Purpose

@e2e exclude No secrets CRUD UI is built in v0.1; all scenarios exercise the encrypted REST API or WebCrypto client logic — covered by integration tests (Postman/PHPUnit), not Playwright UI flows.

Secrets are the core data entity in Doriath. A secret holds sensitive information (passwords, API keys, tokens, etc.) for a user or application. All sensitive fields are encrypted at rest using the owner's EncryptionSuite public certificate. Only the secret's name and URL are stored in plain text to allow listing and searching without decryption. Secrets can be organised into a folder hierarchy per user.

## Data Model

### SecretType

Defines the type of a secret. Type is a UI hint only — it drives how the UI labels and presents fields, but does not affect server-side validation or the underlying data model.

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `name` | string | No | Slug identifier (e.g. `api_key`, `wifi_password`) — unique |
| `label` | string | No | Human-readable display name (e.g. "API Key", "WiFi Password") |
| `scope` | enum | No | `system` (built-in), `user` (created by a specific user), `global` (created by admin, visible to all) |
| `owner_id` | string | No | Nextcloud user ID for `user` scope; null for `system` and `global` |
| `created_at` | datetime | No | |

System types are seeded on install and cannot be modified or deleted:

| Name | Label |
|------|-------|
| `login` | Login |
| `api_key` | API Key |
| `ssh_key` | SSH Key |
| `certificate` | Certificate |
| `note` | Secure Note |
| `database` | Database |

### Folder

Folders are owned per user or application and form a tree via `parent_id`. Folders have no path string — the full path is derived by traversing parents. Folder names are stored unencrypted as they are organisational metadata, not sensitive.

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `name` | string | No | Folder name — single path segment, no slashes |
| `parent_id` | FK | No | Parent Folder; null = root level |
| `owner_type` | enum | No | `user` or `application` |
| `owner_id` | string | No | Nextcloud user ID or Application ID |
| `created_at` | datetime | No | |
| `updated_at` | datetime | No | |

### Secret

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `name` | string | No | Human-readable label — safe to display in lists and search results |
| `url` | string | No | The URL this secret is intended for — stored unencrypted to enable search |
| `type_id` | FK | No | The SecretType; defaults to the `login` system type |
| `folder_id` | FK | No | The Folder this secret belongs to; null = root level |
| `key` | text | Yes | The actual secret value (password, API key, token, etc.) |
| `login` | string | Yes | Optional username, client ID, or equivalent |
| `additional_fields` | text | Yes | JSON blob of extra key-value pairs |
| `encryption_suite_id` | FK | No | Which EncryptionSuite was used to encrypt this secret |
| `owner_type` | enum | No | `user` or `application` |
| `owner_id` | string | No | Nextcloud user ID or Application ID |
| `possibly_compromised_at` | datetime | No | Set during compromise recovery migration; null if not compromised. Signals the user should rotate this secret's value. |
| `migration_error` | text | No | Set when re-encryption fails during compromise recovery; null on success. Cleared on successful retry. |
| `created_at` | datetime | No | |
| `updated_at` | datetime | No | |

## Requirements

### Requirement: Create Secret
The system MUST allow an authenticated user to create a secret with at minimum a name and a key value.

#### Scenario: Create with required fields
- GIVEN a user has an active EncryptionSuite and their master password is in session
- WHEN they submit a new secret with a name and key value
- THEN the system MUST encrypt the key with their public certificate and store it

#### Scenario: Create with all fields
- GIVEN a user has an active EncryptionSuite and their master password is in session
- WHEN they submit a secret with name, url, folder, key, login, and additional fields
- THEN all fields except name, url, and folder_id MUST be stored encrypted

#### Scenario: Create in a folder
- GIVEN a user has a folder in their vault
- WHEN they create a secret with that folder's id as folder_id
- THEN the secret MUST appear under that folder

### Requirement: Read Secret
The system MUST decrypt and return secret fields when the user has their master password in session. The Doriath app UI requires the master password to be in session before any secrets are accessible — the lock screen gates all app routes.

#### Scenario: Read own secret
- GIVEN a user has the master password in session
- WHEN they request a secret they own
- THEN the system MUST return all decrypted fields

#### Scenario: API list without master password
- GIVEN a user does NOT have their master password in session
- WHEN they call the list secrets API directly
- THEN the system MUST return only name, url, and folder_id (no decrypted values)
- NOTE: the app UI prevents reaching this state — this is an API-level contract only

### Requirement: Update Secret
The system MUST allow a user to update any field of a secret they own, including moving it to a different folder. Updated encrypted fields MUST be re-encrypted before storage.

### Requirement: Delete Secret
The system MUST allow a user to delete a secret they own. Deletion MUST cascade to any SecretShares derived from this secret and any SecretRequests linked to it.

### Requirement: Encryption Suite Link
Each secret MUST record which EncryptionSuite was used to encrypt it, so that the correct private key can be identified for decryption (relevant when multiple encryption suites exist).

### Requirement: Revoked Suite Access Block
The system MUST refuse to decrypt any secret whose `encryption_suite_id` points to a suite with status `revoked` or `compromised`. This applies regardless of whether the user has their master password in session.

Secrets associated with a revoked suite MUST still appear in list and search results (name and url are visible) but their encrypted fields MUST NOT be returned. The response MUST indicate that the secret is inaccessible due to a revoked suite.

Secrets associated with a revoked suite become accessible again automatically once the suite is reinstated — no user action or migration is required.

#### Scenario: Read secret with revoked suite
- GIVEN a secret's encryption_suite_id points to a suite with status `revoked`
- WHEN the user requests the secret
- THEN the system MUST return a 403 response indicating the suite is revoked
- AND MUST NOT return any decrypted fields

#### Scenario: Secret accessible after reinstatement
- GIVEN a secret's suite was revoked and has now been reinstated
- WHEN the user requests the secret with their master password in session
- THEN the system MUST decrypt and return all fields normally

### Requirement: Secret Types
Every secret MUST have a type. The type is a UI hint only — it drives how the UI labels and presents fields but does not affect server-side validation or the underlying data model. If no type is specified at creation, the `login` system type MUST be used as default.

System types are built-in and cannot be modified or deleted. Users may create their own types (visible only to them). Administrators may create global types visible to all users on the instance.

#### Scenario: Create secret with type
- GIVEN a user creates a secret and specifies a type
- WHEN the secret is stored
- THEN the secret MUST reference the specified SecretType

#### Scenario: Create secret without type
- GIVEN a user creates a secret without specifying a type
- WHEN the secret is stored
- THEN the secret MUST default to the `login` system type

#### Scenario: User creates custom type
- GIVEN a user creates a SecretType with scope `user`
- THEN the type MUST be available only to that user when creating or filtering secrets

#### Scenario: Admin creates global type
- GIVEN an admin creates a SecretType with scope `global`
- THEN the type MUST be available to all users on the instance

#### Scenario: Delete system type blocked
- GIVEN a system SecretType (scope `system`)
- WHEN a user or admin attempts to delete or modify it
- THEN the system MUST return an error

#### Scenario: Delete custom type with secrets assigned
- GIVEN a user-scoped SecretType has secrets assigned to it
- WHEN the user deletes the type
- THEN all secrets of that type MUST fall back to the `login` system type

### Requirement: Folder Management
The system MUST allow users to create, rename, move, and delete folders to organise their secrets.

Folders use slash-separated path notation for display purposes (`personal/email/work`) but are stored as a tree via `parent_id` — path strings are derived by traversing parents and are never stored directly.

Each user's folder tree is independent. A received share is placed in the recipient's own folder structure — the owner's folder organisation is not visible to or imposed on the recipient.

#### Scenario: Create folder
- GIVEN a user is authenticated
- WHEN they create a folder with a name and optional parent
- THEN the folder MUST be created under the specified parent, or at root if no parent is given

#### Scenario: Rename folder
- GIVEN a user owns a folder
- WHEN they rename it
- THEN the folder name MUST be updated and all contained secrets are unaffected

#### Scenario: Move folder
- GIVEN a user owns a folder
- WHEN they move it to a different parent (or to root)
- THEN the folder's `parent_id` MUST be updated
- AND all secrets and subfolders within it MUST move with it implicitly

#### Scenario: Delete empty folder
- GIVEN a folder contains no secrets and no subfolders
- WHEN the user deletes it
- THEN the folder MUST be removed

#### Scenario: Delete non-empty folder — default
- GIVEN a folder contains secrets or subfolders
- WHEN the user deletes it without a cascade parameter
- THEN the system MUST return a 409 Conflict error: "Folder is not empty"

#### Scenario: Delete folder without subfolders — cascade=delete
- GIVEN a folder contains secrets but NO subfolders
- WHEN the user deletes it with `?cascade=delete`
- THEN the folder and all its direct secrets MUST be deleted

#### Scenario: Delete folder without subfolders — cascade=move
- GIVEN a folder contains secrets but NO subfolders
- WHEN the user deletes it with `?cascade=move`
- THEN all direct secrets MUST be moved to the folder's parent, or to root if the folder has no parent
- AND the folder MUST be deleted

#### Scenario: Delete folder with subfolders — user-directed resolution
- GIVEN a folder contains subfolders (with or without direct secrets)
- WHEN the user requests deletion
- THEN the frontend MUST first call `GET /folders/{id}/children` to retrieve the direct subfolders with their secret and subfolder counts
- AND the frontend MUST present a resolution dialog where the user chooses:
  - For direct secrets: `delete` or `move` (to the deleted folder's parent)
  - For each direct subfolder, one of three actions: `delete`, `move`, or `keep`
- AND the frontend MUST send the resolution plan in the DELETE request body

The three subfolder actions have recursive semantics:
- **`delete`** — recursively delete the subfolder, all its secrets, and all nested subfolders (depth-first)
- **`move`** — recursively collect all secrets from the subfolder's entire subtree, move them to the deleted folder's parent (or root), then delete the subfolder and all nested subfolders
- **`keep`** — re-parent the subfolder to the deleted folder's parent (or root); all contents remain inside it unchanged

#### Scenario: Delete folder with subfolders — API contract
- GIVEN a folder has subfolders
- WHEN the user sends `DELETE /folders/{id}` with a JSON body
- THEN the body MUST have this shape:
  ```json
  {
    "directSecrets": "delete" | "move",
    "subfolders": {
      "<subfolder-id>": "delete" | "move" | "keep",
      "<subfolder-id>": "delete" | "move" | "keep"
    }
  }
  ```
- AND the `subfolders` map MUST include an entry for every direct subfolder
- AND if any direct subfolder is missing from the map, the system MUST return 400 Bad Request
- AND each subfolder action applies recursively to that subfolder's entire subtree

#### Scenario: Delete folder with subfolders — no body provided
- GIVEN a folder has subfolders
- WHEN the user sends `DELETE /folders/{id}` with only `?cascade=delete` or `?cascade=move` (no body)
- THEN the system MUST return a 409 Conflict error: "Folder contains subfolders — resolution required"
- NOTE: the query-parameter shorthand only works for folders without subfolders

### Requirement: List Folder Children
The system MUST provide an endpoint to list a folder's direct children (subfolders and secret counts) so the frontend can build the subfolder resolution dialog.

`GET /folders/{id}/children` MUST return:
- `directSecretCount` — number of secrets directly in this folder
- `subfolders` — array of direct child folders, each with:
  - `id` — folder UUID
  - `name` — folder name
  - `secretCount` — total number of secrets in this subfolder (recursive count, all descendants)
  - `subfolderCount` — number of direct child subfolders within this subfolder

#### Scenario: List children of a folder
- GIVEN a user owns a folder with 2 secrets and 1 subfolder (containing 3 secrets and 1 nested subfolder)
- WHEN they call `GET /folders/{id}/children`
- THEN the system MUST return `directSecretCount: 2` and a subfolders array with one entry showing `secretCount: 3, subfolderCount: 1`

#### Scenario: List children of a leaf folder
- GIVEN a folder has no subfolders and 5 secrets
- WHEN the user calls `GET /folders/{id}/children`
- THEN the system MUST return `directSecretCount: 5` and an empty subfolders array

### Requirement: List and Pagination
The system MUST return secrets in a paginated list. The list MUST include secrets owned by the user and secrets shared with the user (received shares), treated identically. The list MAY be filtered by folder.

The list MUST support sorting by:
- `name` (alphabetical, default)
- `url` (alphabetical)
- `created_at`
- `updated_at`

Each list response MUST include the total count of matching records to support pagination controls.

#### Scenario: List own and received secrets
- GIVEN a user has their master password in session
- WHEN they request their secrets list
- THEN the system MUST return both owned secrets and received shares in the same list

#### Scenario: List secrets in a folder
- GIVEN a user has their master password in session
- WHEN they request secrets filtered by a specific folder
- THEN the system MUST return only secrets with that folder_id

### Requirement: Search
The system MUST allow users to search their secrets by `name` and `url` using fuzzy matching. Search MUST tolerate typos up to a reasonable degree (e.g. Levenshtein distance ≤ 1 for strings up to 5 characters, ≤ 2 for longer strings) but MUST NOT return results with no meaningful similarity to the query.

Received shares MUST be included in search results and treated identically to owned secrets.

Search requires the master password to be in session (the user must be inside the app).

#### Scenario: Search by name
- GIVEN a user has their master password in session
- WHEN they search for "Githb"
- THEN the system MUST return secrets whose name matches "GitHub" (typo tolerance)

#### Scenario: Search by url
- GIVEN a user has their master password in session
- WHEN they search for "github.com"
- THEN the system MUST return secrets whose url contains or fuzzy-matches "github.com"

#### Scenario: No meaningful match
- GIVEN a user searches for a string with no similarity to any name or url
- WHEN the query is processed
- THEN the system MUST return an empty result set

### Requirement: Nextcloud Unified Search Integration
The system MUST register a Nextcloud search provider (`OCP\Search\IProvider`) so that secrets are discoverable via the Nextcloud unified search (Ctrl+F / search bar).

The search provider MUST query `name` and `url` directly from the database without requiring the Doriath AES key to be in session. Results MUST be scoped to the authenticated Nextcloud user's secrets (owned and received shares).

Clicking a search result MUST deep-link into Doriath:
- If the user has an active Doriath session: navigate directly to the secret
- If the user does not have an active Doriath session: redirect to the Doriath lock screen; after successful unlock, redirect to the intended secret

The lock screen MUST support a return URL parameter so the post-unlock redirect works correctly.

#### Scenario: Search result clicked with active session
- GIVEN a user has an active Doriath session
- WHEN they click a secret in the Nextcloud unified search results
- THEN they MUST be taken directly to that secret in Doriath

#### Scenario: Search result clicked without active session
- GIVEN a user does NOT have an active Doriath session
- WHEN they click a secret in the Nextcloud unified search results
- THEN they MUST be redirected to the Doriath lock screen
- AND after entering their master password they MUST be redirected to the intended secret

## User Stories

- As a user, I want to store a password with a name so that I can retrieve it later without remembering it
- As a user, I want to store a username alongside a password so that I have the full credential in one place
- As a user, I want to store the URL a secret belongs to so that I can find it when I need to log in to a site
- As a user, I want to add additional fields to a secret so that I can store any relevant metadata (e.g., notes)
- As a user, I want to assign a type to a secret so that the UI presents the right fields with the right labels
- As a user, I want to create my own secret types so that I can model secrets that don't fit the built-in types
- As an admin, I want to create global secret types so that all users on the instance can use them
- As a user, I want to organise my secrets into folders so that I can keep work and personal secrets separate
- As a user, I want to move a secret to a different folder so that I can reorganise my vault
- As a user, I want to place a received share in my own folder structure independently of the sender's organisation
- As a user, I want to search my secrets by name or URL so that I can quickly find what I need
- As a user, I want typo-tolerant search so that a small spelling mistake does not prevent me from finding a secret
- As a user, I want to find my secrets from the Nextcloud search bar so that I do not have to open Doriath first
- As a user, I want to sort my secrets by name, URL, or date so that I can browse them in a useful order
- As a user, I want to delete a secret I no longer need
- As a user, I want to delete a folder and choose what happens to each subfolder (delete, move contents up, or keep) so that I don't accidentally lose secrets
- As a user, I want to see how many secrets each subfolder contains before deciding what to do with it

## Acceptance Criteria

- [ ] Secrets can be created with name + key (minimum)
- [ ] Every secret has a type; defaults to `login` if not specified
- [ ] Six system types are seeded on install and cannot be modified or deleted
- [ ] Users can create, rename, and delete their own (user-scoped) types
- [ ] Admins can create, rename, and delete global types
- [ ] Deleting a custom type reassigns its secrets to the `login` system type
- [ ] URL, folder, login, and additional fields are optional
- [ ] Name, url, and folder_id are stored and returned in plain text
- [ ] Key, login, and additional fields are stored encrypted and returned decrypted when master password is in session
- [ ] The app UI requires the master password to be in session before any secrets are accessible
- [ ] The API returns only name, url, and folder_id when no AES key is in session (API-level contract)
- [ ] Folders can be created, renamed, moved, and deleted
- [ ] Folder paths are displayed using slash notation derived from the folder tree
- [ ] Each user's folder structure is independent — received shares are placed in the recipient's own folders
- [ ] Deleting a non-empty folder without a cascade parameter returns 409 Conflict
- [ ] `?cascade=delete` deletes the folder and its direct secrets (when folder has no subfolders)
- [ ] `?cascade=move` moves direct secrets to the parent folder or root (when folder has no subfolders)
- [ ] Deleting a folder with subfolders using only `?cascade=` (no body) returns 409 with "resolution required"
- [ ] `GET /folders/{id}/children` returns direct secret count and subfolder list with recursive secret counts
- [ ] DELETE with a resolution body is accepted: each subfolder mapped to `delete`, `move`, or `keep`
- [ ] Missing subfolder entries in the resolution body return 400 Bad Request
- [ ] Subfolder action `delete` recursively removes the subfolder and all descendants
- [ ] Subfolder action `move` recursively collects all secrets from the subtree and moves them to the parent
- [ ] Subfolder action `keep` re-parents the subfolder to the deleted folder's parent
- [ ] Received shares appear in the secrets list alongside owned secrets
- [ ] The list is paginated and includes a total count
- [ ] The list can be filtered by folder
- [ ] The list can be sorted by name, url, created_at, or updated_at
- [ ] Search queries name and url with fuzzy matching and typo tolerance
- [ ] Typo tolerance is bounded — queries with no meaningful similarity return empty results
- [ ] Received shares are included in search results
- [ ] Doriath registers a Nextcloud unified search provider
- [ ] The unified search provider queries name and url without requiring an active Doriath session
- [ ] Clicking a unified search result deep-links to the secret, via the lock screen if the session is not active
- [ ] The lock screen supports a return URL for post-unlock redirect
- [ ] Deleting a secret cascades to all derived shares and requests
- [ ] Each secret records the `encryption_suite_id` used for encryption
- [ ] Secrets with a revoked or compromised suite appear in lists but cannot be decrypted
- [ ] Decryption requests for secrets on a revoked suite return 403
- [ ] Secrets become accessible again automatically when their suite is reinstated
- [ ] Secrets are isolated per owner — a user cannot read another user's secrets directly

## Open Questions

- **Pagination approach**: **Resolved.** Classic pagination with 50 items per page. Team preference — straightforward, consistent with other Conduction apps.
- **Subfolder cascade**: **Resolved.** User-directed resolution — when a folder has subfolders, the frontend presents a dialog where the user chooses per subfolder: `delete` (recursive), `move` (flatten subtree to parent), or `keep` (re-parent). Folders without subfolders use the simple `?cascade=delete` / `?cascade=move` query parameters. See "Delete folder with subfolders" scenarios above.

## Notes

- **Secret visibility beyond own vault** (to be addressed in sharing and application-mgmt changes): By default, users can only see and search their own secrets (and received shares). However, two future cases require broader visibility of secret *metadata* (names/URLs only, never decrypted values): (1) When sharing a secret, users may need to see that a target user or application has a vault, but they do NOT browse the target's secrets — sharing is initiated from the sender's own secret. (2) Users who manage an application should be able to browse that application's vault metadata to write new secrets or manage existing ones. The visibility model for these cases should be defined in the `implement-sharing` and `implement-application-mgmt` changes respectively.
- `url` is stored unencrypted by design — it enables search and Nextcloud unified search integration without requiring the master password. Users should be aware that URLs are visible in the database.
- Folder names are stored unencrypted — they are organisational metadata, not sensitive values.
- Folder paths are never stored as strings; they are derived at query time by traversing `parent_id` links.
- Additional fields are encrypted as a JSON blob. Chunking must be implemented before large additional values are supported (see ADR-003 on RSA chunk limits).
- The key generator feature integrates with secret creation to auto-generate the key value.
- **Access log** (V1, for dashboard "recently accessed" widget): A `doriath_access_log` table tracks secret access events (secret_id, user_id, accessed_at). Populated by SecretService on each read. Used by the dashboard to show the 5 most recently accessed secrets. The migration for this table should be added when implementing the V1 dashboard features.
- Related ADRs: ADR-001 (own DB tables), ADR-003 (encryption architecture)
