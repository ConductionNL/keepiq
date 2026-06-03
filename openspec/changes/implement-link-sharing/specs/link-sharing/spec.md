## ADDED Requirements

### Requirement: Create Link Share
The system MUST allow a user to create a password-protected link share for a secret they own. The browser MUST decrypt the secret using the owner's CryptoKey, generate a high-entropy random password, derive an AES-256 key from that password via Argon2id (WASM), encrypt a snapshot of the secret with the derived key, and POST the encrypted blob to the server. The server MUST generate a URL-safe token with at least 128 bits of entropy via `random_bytes()`, store the encrypted snapshot, and return the link URL and token. The link password MUST NOT be sent to or stored on the server.

The encrypted snapshot is a point-in-time copy of the secret's sensitive fields (key, login, additional_fields) serialized as a JSON object and encrypted as a single AES-256-GCM blob. The snapshot does NOT use RSA chunking — it uses a symmetric AES key derived from the link password.

The usage limit MUST be specified on creation. Valid range: 1 to 10 inclusive. Default: 1. The system MUST reject values outside this range. There is no unlimited option.

#### Scenario: Create link share with default usage limit
- **WHEN** the owner creates a link share for a secret they own without specifying a usage limit
- **THEN** the system MUST create the link share with usage_limit = 1, generate a token, encrypt the snapshot with an Argon2id-derived AES key, store only the encrypted blob, and return the link URL and the generated password to the user

#### Scenario: Create link share with custom usage limit
- **WHEN** the owner creates a link share with usage_limit = 5
- **THEN** the system MUST create the link share with usage_limit = 5

#### Scenario: Reject invalid usage limit
- **WHEN** the owner creates a link share with usage_limit = 0, usage_limit = 11, or usage_limit = null
- **THEN** the system MUST return a 400 error and MUST NOT create the link share

#### Scenario: Password shown once
- **WHEN** the link share is created successfully
- **THEN** the generated password MUST be displayed to the user exactly once with a copy button
- **THEN** the password MUST NOT be recoverable after the user navigates away

### Requirement: Access Link Share via Public Page
The system MUST provide a public page at `/share/link/:token` accessible WITHOUT Nextcloud authentication. The visitor enters the link password. The server validates the token, checks that usage_count < usage_limit and that the link has not been brute-force deleted, and returns the encrypted snapshot blob. The browser derives the AES-256 key from the entered password via Argon2id (WASM) and decrypts the snapshot client-side.

On successful decryption, the browser MUST call a confirmation endpoint to increment the usage count. The server MUST NOT increment the usage count until the browser confirms successful decryption.

#### Scenario: Valid access with correct password
- **WHEN** a visitor navigates to `/share/link/:token` and enters the correct password
- **THEN** the server MUST return the encrypted snapshot blob
- **THEN** the browser MUST derive the AES key via Argon2id and decrypt the snapshot
- **THEN** the browser MUST call the confirmation endpoint
- **THEN** the server MUST increment usage_count by 1

#### Scenario: Access with incorrect password
- **WHEN** a visitor enters an incorrect password for a valid token
- **THEN** the browser MUST attempt to derive the AES key and decrypt
- **THEN** decryption MUST fail (AES-GCM authentication tag mismatch)
- **THEN** the browser MUST display an error message without exposing any secret data
- **THEN** the server MUST increment the failed_attempts counter for the token

#### Scenario: Token not found
- **WHEN** a visitor navigates to `/share/link/:token` with an invalid or deleted token
- **THEN** the server MUST return a 404 error with a generic "link not found or expired" message

#### Scenario: Expired link share
- **WHEN** a visitor accesses a link share whose `expires_at` has passed
- **THEN** the server MUST return a 404 error and MUST delete the expired link share

### Requirement: Usage Limit Enforcement
The system MUST enforce the usage limit. When usage_count reaches usage_limit after a successful confirmation, the link share and its encrypted snapshot MUST be automatically deleted.

#### Scenario: Last allowed access
- **WHEN** a visitor successfully decrypts a link share and the confirmation causes usage_count to equal usage_limit
- **THEN** the server MUST delete the link share immediately after confirming the access

#### Scenario: Access after usage limit reached
- **WHEN** the usage_count equals usage_limit before the access attempt
- **THEN** the server MUST return a 404 error and MUST NOT return the encrypted blob

### Requirement: Brute-Force Protection
The system MUST track consecutive failed password attempts per token via a `failed_attempts` column. After 5 consecutive failed attempts, the link share MUST be permanently deleted. This prevents offline brute-force attacks against captured tokens.

The failed_attempts counter is incremented server-side when the blob is fetched but no successful confirmation follows within a timeout window (e.g., 60 seconds). Alternatively, the browser can explicitly report decryption failure. On successful confirmation, the counter resets to 0.

#### Scenario: Five consecutive failures
- **WHEN** 5 consecutive incorrect passwords are submitted for a token (no successful confirmation between them)
- **THEN** the server MUST permanently delete the link share
- **THEN** subsequent requests with the same token MUST return a 404 error

#### Scenario: Successful access resets counter
- **WHEN** a visitor has 3 failed attempts followed by a successful confirmation
- **THEN** the failed_attempts counter MUST reset to 0

### Requirement: Snapshot Staleness
A link share captures a point-in-time snapshot of the secret at creation. If the original secret is updated after link creation, the link share continues to serve the original snapshot. The owner MUST revoke and re-create the link to share an updated value.

#### Scenario: Secret updated after link creation
- **WHEN** the owner updates the original secret after creating a link share
- **THEN** the link share MUST continue to serve the snapshot from creation time
- **THEN** the link share MUST NOT reflect the updated secret values

### Requirement: Multiple Concurrent Link Shares
A secret MAY have multiple active link shares simultaneously. Each link share has its own token, password, usage limit, usage count, and lifecycle. Creating a new link share MUST NOT affect existing link shares for the same secret.

#### Scenario: Create second link share
- **WHEN** the owner creates a second link share for a secret that already has an active link share
- **THEN** both link shares MUST remain active and independent
- **THEN** each link share MUST have its own unique token and password

### Requirement: Manual Revocation
The secret owner MUST be able to revoke (delete) a link share at any time before the usage limit is reached. Revocation permanently deletes the link share and its encrypted snapshot.

#### Scenario: Owner revokes link share
- **WHEN** the owner deletes an active link share
- **THEN** the link share and its encrypted snapshot MUST be permanently deleted
- **THEN** subsequent access attempts with the token MUST return a 404 error

#### Scenario: Non-owner cannot revoke
- **WHEN** a user who does not own the secret attempts to delete the link share
- **THEN** the system MUST return a 403 error

### Requirement: Argon2id KDF for Snapshot Encryption
The snapshot MUST be encrypted using AES-256-GCM with a key derived from the link password via Argon2id. Argon2id MUST be executed in the browser via a WASM library (e.g., `argon2-browser`). Argon2id is chosen over PBKDF2 for its memory-hardness, which significantly increases the cost of brute-force attacks against a captured encrypted snapshot.

Argon2id parameters MUST be: memory 64 MiB, iterations 3, parallelism 1, output length 32 bytes. The salt MUST be a random 16-byte value generated at link creation time and stored alongside the encrypted snapshot.

#### Scenario: Argon2id derivation round-trip
- **WHEN** a link share is created with password P and salt S
- **THEN** deriving the AES key from P and S via Argon2id with the specified parameters MUST produce a key that successfully decrypts the snapshot
- **THEN** deriving the AES key from a different password P' and the same salt S MUST produce a key that fails to decrypt (GCM tag mismatch)

### Requirement: Link Share Cascade on Secret Deletion
When a secret is deleted, all its associated link shares MUST be cascade-deleted.

#### Scenario: Secret deleted with active link shares
- **WHEN** the owner deletes a secret that has 3 active link shares
- **THEN** all 3 link shares MUST be permanently deleted

### Requirement: Link Share Cascade on Compromise Recovery
When a user initiates compromise recovery (key rotation), all link shares for the user's secrets MUST be revoked (deleted). Re-creating link shares requires the new encryption suite.

#### Scenario: Compromise recovery deletes link shares
- **WHEN** the user initiates compromise recovery
- **THEN** all link shares for the user's secrets MUST be permanently deleted
