## ADDED Requirements

### Requirement: Create Secret
The system MUST allow an authenticated user to create a secret with at minimum a name and a key value. The key, login, and additional_fields MUST be RSA-encrypted with the owner's public certificate before storage. The name, url, and folder_id MUST be stored in plaintext. If no type is specified, the system MUST assign the `login` system type. The system MUST record the encryption_suite_id used for encryption. The system MUST reject creation if the user's EncryptionSuite is revoked or compromised, or if a write lock is active (compromise recovery in progress).

#### Scenario: Create with required fields only
- **WHEN** a user with an active EncryptionSuite submits a new secret with a name and an encrypted key value
- **THEN** the system MUST store the secret with the encrypted key, assign the `login` system type, set folder_id to null (root level), set owner_type to `user` and owner_id to the user's UID, and record the encryption_suite_id

#### Scenario: Create with all fields
- **WHEN** a user submits a secret with name, url, folder_id, encrypted key, encrypted login, encrypted additional_fields, and a type_id
- **THEN** the system MUST store all fields, with key, login, and additional_fields as encrypted blobs and name, url, folder_id as plaintext

#### Scenario: Create in a folder
- **WHEN** a user creates a secret with a folder_id referencing a folder they own
- **THEN** the secret MUST be stored with that folder_id and appear under that folder in listings

#### Scenario: Create rejected when suite is revoked
- **WHEN** a user with a revoked EncryptionSuite attempts to create a secret
- **THEN** the system MUST return a 403 error indicating the suite is revoked

#### Scenario: Create rejected during write lock
- **WHEN** a user with an in-progress compromise recovery attempts to create a secret
- **THEN** the system MUST return a 423 Locked error indicating migration is in progress

### Requirement: Read Secret
The system MUST return secret data including encrypted blobs when the user owns the secret or has a share. Encrypted fields (key, login, additional_fields) MUST be returned as encrypted blobs -- the server MUST NOT decrypt them. The browser decrypts using the CryptoKey held in the session store. The API MUST always return encrypted blobs for encrypted fields, regardless of session state. The name, url, type, and folder metadata MUST always be returned in plaintext.

#### Scenario: Read own secret
- **WHEN** a user requests a secret they own
- **THEN** the system MUST return all fields including encrypted blobs for key, login, and additional_fields, plus plaintext name, url, type, and folder metadata

#### Scenario: Read secret with revoked suite
- **WHEN** a user requests a secret whose encryption_suite_id references a suite with status `revoked` or `compromised`
- **THEN** the system MUST return a 403 error indicating the suite is revoked
- **AND** MUST NOT return the encrypted blobs

#### Scenario: Secret accessible after suite reinstatement
- **WHEN** a secret's suite was revoked and has been reinstated (status returned to `active`)
- **THEN** the system MUST return all fields including encrypted blobs normally

### Requirement: Update Secret
The system MUST allow a user to update any field of a secret they own. Updated encrypted fields MUST be re-encrypted (client-side) before submission. The system MUST record the updated_at timestamp. Moving a secret to a different folder MUST update folder_id. The system MUST reject updates if a write lock is active.

#### Scenario: Update plaintext fields
- **WHEN** a user updates the name or url of a secret they own
- **THEN** the system MUST update the plaintext fields and set updated_at

#### Scenario: Update encrypted fields
- **WHEN** a user updates the key or login of a secret they own
- **THEN** the system MUST store the new encrypted blobs and set updated_at

#### Scenario: Move secret to different folder
- **WHEN** a user updates a secret's folder_id to a different folder they own
- **THEN** the system MUST update folder_id and the secret MUST appear under the new folder

#### Scenario: Update rejected during write lock
- **WHEN** a user with an in-progress compromise recovery attempts to update a secret
- **THEN** the system MUST return a 423 Locked error

### Requirement: Delete Secret
The system MUST allow a user to delete a secret they own. Deletion MUST cascade to any SecretShares derived from this secret and any SecretRequests linked to it. The cascade MUST happen atomically (all or nothing).

#### Scenario: Delete own secret
- **WHEN** a user deletes a secret they own
- **THEN** the secret MUST be removed from the database
- **AND** all SecretShares referencing this secret as source_secret_id MUST be deleted
- **AND** all SecretRequests referencing this secret MUST be deleted

#### Scenario: Delete secret that is not owned
- **WHEN** a user attempts to delete a secret they do not own
- **THEN** the system MUST return a 403 error

### Requirement: Encryption Suite Link
Each secret MUST record which EncryptionSuite was used to encrypt it via the `encryption_suite_id` foreign key. This ensures the correct private key can be identified for decryption when multiple suites exist (e.g., after compromise recovery).

#### Scenario: Secret records encryption suite
- **WHEN** a secret is created
- **THEN** the secret MUST have a non-null encryption_suite_id referencing the active EncryptionSuite of the owner

### Requirement: Revoked Suite Access Block
The system MUST refuse to return encrypted fields for any secret whose encryption_suite_id points to a suite with status `revoked` or `compromised`. Secrets with a blocked suite MUST still appear in list and search results with their plaintext metadata (name, url, type, folder). The response MUST include a `blocked` flag set to `true` and a `blocked_reason` string. After the suite is reinstated, secrets MUST become accessible again automatically.

#### Scenario: List includes blocked secrets with metadata only
- **WHEN** a user lists their secrets and some are associated with a revoked suite
- **THEN** the blocked secrets MUST appear in the list with name, url, type, and folder metadata
- **AND** encrypted fields MUST be omitted
- **AND** a `blocked: true` flag and `blocked_reason` MUST be included

#### Scenario: Blocked secret detail returns 403
- **WHEN** a user requests the detail of a secret with a revoked suite
- **THEN** the system MUST return 403 with an error indicating the suite is revoked

#### Scenario: Secrets unblocked after reinstatement
- **WHEN** a revoked suite is reinstated
- **THEN** all secrets linked to that suite MUST be returned with full encrypted blobs on subsequent requests

### Requirement: List and Pagination
The system MUST return secrets in a paginated list with 50 items per page (configurable up to 100 via `limit` parameter). The list MUST include secrets owned by the user. The list MUST support filtering by folder_id. The list MUST support sorting by name (default, alphabetical), url (alphabetical), created_at, and updated_at. Each response MUST include a `total` count of matching records.

#### Scenario: List first page of secrets
- **WHEN** a user requests their secrets without pagination parameters
- **THEN** the system MUST return the first 50 secrets sorted by name ascending and a total count

#### Scenario: List with pagination
- **WHEN** a user requests page 2 with limit 50
- **THEN** the system MUST return secrets 51-100 (if they exist) and the total count

#### Scenario: List filtered by folder
- **WHEN** a user requests secrets with folder_id set to a specific folder
- **THEN** the system MUST return only secrets with that folder_id

#### Scenario: List sorted by date
- **WHEN** a user requests secrets sorted by created_at descending
- **THEN** the system MUST return secrets in reverse chronological order

### Requirement: Search
The system MUST allow users to search their secrets by name and url using fuzzy matching. Search MUST tolerate typos with Levenshtein distance up to 1 for terms of 5 characters or fewer, and up to 2 for longer terms. Search MUST NOT return results with no meaningful similarity to the query. Search results MUST be paginated and include a total count.

#### Scenario: Exact substring match
- **WHEN** a user searches for "GitHub"
- **THEN** the system MUST return secrets whose name or url contains "GitHub"

#### Scenario: Fuzzy match with typo
- **WHEN** a user searches for "Githb"
- **THEN** the system MUST return secrets whose name fuzzy-matches "GitHub" (Levenshtein distance 1)

#### Scenario: URL search
- **WHEN** a user searches for "github.com"
- **THEN** the system MUST return secrets whose url contains or fuzzy-matches "github.com"

#### Scenario: No meaningful match
- **WHEN** a user searches for "xyzzyplugh"
- **THEN** the system MUST return an empty result set

### Requirement: Nextcloud Unified Search Integration
The system MUST register a Nextcloud search provider implementing `OCP\Search\IProvider`. The provider MUST query the `name` and `url` columns directly from the database without requiring an active Doriath session or master password. Results MUST be scoped to the authenticated Nextcloud user's secrets. Each result MUST include a deep-link URL to the secret in Doriath.

#### Scenario: Unified search returns matching secrets
- **WHEN** a user types a query in the Nextcloud unified search bar
- **THEN** the provider MUST return secrets whose name or url matches the query, with title, subtitle, icon, and deep-link URL

#### Scenario: Deep-link with active Doriath session
- **WHEN** a user clicks a unified search result and has an active Doriath session
- **THEN** the user MUST be navigated directly to the secret detail view

#### Scenario: Deep-link without active Doriath session
- **WHEN** a user clicks a unified search result and does NOT have an active Doriath session
- **THEN** the user MUST be redirected to the Doriath lock screen with a returnUrl parameter
- **AND** after entering their master password, the user MUST be redirected to the intended secret

### Requirement: Copy-to-Clipboard
The system MUST allow users to copy the decrypted key value of a secret to the clipboard from the secret list view without navigating to the detail view. The copy action MUST trigger on-demand decryption if the key has not already been decrypted. A visual confirmation (icon change or toast) MUST be shown after successful copy. The clipboard MUST be cleared after a configurable timeout (default: 30 seconds).

#### Scenario: Copy password from list view
- **WHEN** a user clicks the copy button on a secret list item
- **THEN** the system MUST decrypt the key field using the session CryptoKey and copy the plaintext to the clipboard
- **AND** display a visual confirmation

#### Scenario: Clipboard auto-clear
- **WHEN** a user copies a secret to the clipboard
- **THEN** the system MUST clear the clipboard after 30 seconds

### Requirement: Show/Hide Toggle
The system MUST provide a toggle on all password/key fields that switches between masked (dots) and plaintext display. Fields MUST default to masked. The first show toggle MUST trigger decryption of the field if it has not been decrypted yet.

#### Scenario: Toggle password visibility
- **WHEN** a user clicks the show/hide toggle on a masked key field
- **THEN** the system MUST decrypt the field (if not already decrypted) and display the plaintext value

#### Scenario: Hide password
- **WHEN** a user clicks the show/hide toggle on a visible key field
- **THEN** the system MUST mask the field value with dots

### Requirement: Favicon Display
The system MUST display a favicon or icon next to each secret in the list and detail views. If the secret has a URL, the system MUST attempt to load the favicon for that domain. If the URL is empty or the favicon fails to load, the system MUST display a type-specific fallback icon.

#### Scenario: Favicon loaded from URL
- **WHEN** a secret has a url with a resolvable domain
- **THEN** the system MUST display the domain's favicon next to the secret

#### Scenario: Fallback to type icon
- **WHEN** a secret has no url or the favicon fails to load
- **THEN** the system MUST display an icon representing the secret's type
