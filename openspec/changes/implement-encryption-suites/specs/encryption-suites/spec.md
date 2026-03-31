## ADDED Requirements

### Requirement: EncryptionSuite Entity and Database Table
The system MUST store EncryptionSuites in the `doriath_encryption_suites` table with the following fields:

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | integer (autoincrement) | No | Primary key |
| `owner_type` | string (enum) | No | `user` or `application` (ADR-002 polymorphic ownership) |
| `owner_id` | string | No | Nextcloud user ID or Application UUID |
| `certificate` | text | No | PEM-encoded X.509 certificate signed by active CA intermediate |
| `private_key` | text | Yes (AES-256-GCM) | Base64-encoded envelope: version + salt + IV + ciphertext + GCM tag |
| `status` | string (enum) | No | `active`, `revoked`, `compromised` |
| `revoked_at` | datetime | No | Null if never revoked |
| `revoked_reason` | string | No | Null if never revoked |
| `revoked_by` | string | No | Nextcloud user ID of revoker; null if never revoked |
| `reinstated_at` | datetime | No | Null if never reinstated |
| `reinstated_by` | string | No | Nextcloud user ID; null if never reinstated |
| `created_at` | datetime | No | Set on creation |

The migration MUST use Nextcloud's `ISchemaWrapper` pattern. The `private_key` field stores the AES-256-GCM encrypted private key using the envelope format defined in design.md (D4). The field is null for CSR-registered application suites where the private key is held externally.

#### Scenario: Database table creation
- **WHEN** Nextcloud runs `occ upgrade` with Doriath installed
- **THEN** the `doriath_encryption_suites` table MUST be created with all specified columns and correct types

### Requirement: Auto-Create Suite on First Login
The system MUST automatically create an EncryptionSuite for a Nextcloud user the first time they open Doriath and provide a master password. The system MUST generate a 4096-bit RSA key pair, sign the public key with the active CA intermediate, and store the private key encrypted with the user's AES-derived key.

#### Scenario: First-time user setup
- **WHEN** a Nextcloud user with no existing EncryptionSuite opens Doriath and provides a master password meeting the strength floor
- **THEN** the system MUST generate a 4096-bit RSA key pair via the browser's WebCrypto API
- **AND** send the public key to the server for X.509 certificate signing by the active CA intermediate
- **AND** encrypt the private key with the AES-256-GCM derived key (client-side) and send the encrypted blob to the server for storage
- **AND** set the suite status to `active`

#### Scenario: User already has active suite
- **WHEN** a Nextcloud user with an existing active EncryptionSuite opens Doriath
- **THEN** the system MUST NOT create a new suite
- **AND** MUST present the lock screen for master password entry

### Requirement: Minimum Key Size
The system MUST generate RSA keys of at least 4096 bits. The minimum key size MUST only be allowed to increase via configuration, never decrease.

#### Scenario: Key generation enforces minimum
- **WHEN** an EncryptionSuite is created
- **THEN** the RSA key pair MUST be at least 4096 bits
- **AND** any configuration attempt to set the key size below 4096 MUST be rejected

### Requirement: Suite Revocation
The system MUST allow a user to revoke their own EncryptionSuite or an administrator to revoke any user's suite. Revocation MUST record the timestamp, reason, and identity of the revoker. A revoked suite MUST NOT be usable for new encryption or decryption operations. The private key remains stored (AES-encrypted, unchanged).

#### Scenario: User revokes own suite
- **WHEN** a user revokes their active EncryptionSuite with a reason
- **THEN** the suite status MUST be set to `revoked`
- **AND** `revoked_at` MUST be set to the current timestamp
- **AND** `revoked_reason` MUST be set to the provided reason
- **AND** `revoked_by` MUST be set to the user's Nextcloud ID

#### Scenario: Admin revokes user suite
- **WHEN** an administrator revokes a user's EncryptionSuite
- **THEN** the same revocation fields MUST be set with the admin's Nextcloud ID as `revoked_by`

#### Scenario: Revoked suite cannot encrypt
- **WHEN** an operation attempts to encrypt data using a revoked EncryptionSuite
- **THEN** the system MUST reject the operation with an error

#### Scenario: Revoked suite cannot decrypt
- **WHEN** an API request attempts to retrieve encrypted data associated with a revoked suite
- **THEN** the API MUST return an error indicating the suite is revoked

### Requirement: Suite Reinstatement
The system MUST allow an administrator to reinstate a revoked EncryptionSuite. Reinstatement MUST re-sign the existing public key with the active CA intermediate, producing a new X.509 certificate. No new key pair is generated and no secret migration is required. Compromised suites MUST NOT be reinstatable.

#### Scenario: Admin reinstates revoked suite
- **WHEN** an administrator reinstates an EncryptionSuite with status `revoked`
- **THEN** the system MUST extract the public key from the existing certificate
- **AND** generate a new X.509 certificate signed by the active CA intermediate
- **AND** update the `certificate` field with the new certificate
- **AND** set the suite status to `active`
- **AND** set `reinstated_at` to the current timestamp and `reinstated_by` to the admin's ID
- **AND** preserve all revocation audit fields (`revoked_at`, `revoked_reason`, `revoked_by`)

#### Scenario: Compromised suite cannot be reinstated
- **WHEN** an administrator attempts to reinstate an EncryptionSuite with status `compromised`
- **THEN** the system MUST return an error indicating compromised suites cannot be reinstated

### Requirement: Compromise Recovery with Key Rotation
When a user indicates their master password was compromised, the system MUST initiate a full key rotation: generate a new RSA-4096 key pair, create a new EncryptionSuite, apply a write lock, and begin migrating all secrets from the old suite to the new suite. The old suite MUST be flagged as `compromised` after migration completes.

#### Scenario: Compromise recovery initiated
- **WHEN** a user selects compromise recovery and provides their old master password and a new master password
- **THEN** the system MUST generate a new RSA-4096 key pair (client-side via WebCrypto)
- **AND** create a new EncryptionSuite signed by the active CA intermediate
- **AND** create a SuiteMigration record with status `in_progress`
- **AND** apply a write lock on the user's account (no create/update operations on secrets)
- **AND** lock all pending SecretRequests for this user

#### Scenario: Old suite marked compromised after migration
- **WHEN** compromise recovery migration completes
- **THEN** the old EncryptionSuite status MUST be set to `compromised`
- **AND** the old suite MUST NOT be usable for any operations

### Requirement: Suite Migration Tracking
The system MUST track suite migrations via the `doriath_suite_migrations` table with fields: `id`, `old_suite_id` (FK), `new_suite_id` (FK), `status` (enum: `in_progress`, `completed`, `completed_with_errors`), `started_at`, `completed_at`. Migration progress is determined by counting secrets still pointing to `old_suite_id`.

#### Scenario: Migration progress tracking
- **WHEN** a suite migration is in progress
- **THEN** the system MUST report progress as the ratio of migrated secrets to total secrets
- **AND** secrets still referencing `old_suite_id` are considered unmigrated

#### Scenario: Tab closed mid-migration
- **WHEN** a user closes all browser tabs during an active suite migration
- **THEN** the write lock MUST remain active server-side
- **AND** the SuiteMigration record MUST remain in `in_progress` status
- **WHEN** the user reopens Doriath
- **THEN** the system MUST show a "migration paused" screen with the count of remaining secrets
- **AND** require the user to re-enter their master password to resume migration

#### Scenario: Migration completes with errors
- **WHEN** one or more secrets fail re-encryption during migration
- **THEN** the SuiteMigration status MUST be set to `completed_with_errors`
- **AND** each failed secret MUST have its `migration_error` field set
- **AND** the user MUST be shown a list of failed secrets with a retry option

#### Scenario: Retry failed secrets
- **WHEN** a user retries previously failed secret migrations
- **THEN** the system MUST require the old (compromised) master password
- **AND** on success, clear `migration_error` and set `possibly_compromised_at` on migrated secrets

### Requirement: Browser-Side Secret Migration
During compromise recovery, secret migration MUST be performed entirely in the browser. The browser holds both the old private key (decrypted with old AES key) and the new public key as WebCrypto CryptoKey objects. For each secret: fetch encrypted blob from API, decrypt with old private key, re-encrypt with new public key, POST re-encrypted blob to API. The server MUST NOT see plaintext secret values at any point.

RSA-4096 with OAEP-SHA256 padding limits plaintext per chunk to 446 bytes. Secrets larger than 446 bytes MUST be chunked identically to the original encryption (chunk count header + fixed-size encrypted chunks).

#### Scenario: Single secret migration
- **WHEN** the browser migrates a single secret
- **THEN** it MUST fetch the encrypted blob via GET
- **AND** decrypt using the old private key (WebCrypto RSA-OAEP)
- **AND** re-encrypt using the new public key (WebCrypto RSA-OAEP) with identical chunking
- **AND** POST the re-encrypted blob to the server
- **AND** the server MUST update the secret's `encryption_suite_id` to the new suite

#### Scenario: Migration post-processing
- **WHEN** all secrets have been processed (migrated or errored)
- **THEN** the system MUST revoke link shares associated with the old suite
- **AND** unlock and re-point locked SecretRequests to the new suite
- **AND** set the SuiteMigration status to `completed` or `completed_with_errors`
- **AND** release the write lock

### Requirement: EncryptionSuite API Endpoints
The system MUST expose REST API endpoints for suite management following Nextcloud API conventions. The `OCSController` base class MUST be used. Endpoints MUST use `OCP\IUserSession` for authentication.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/suites` | List suites for current user |
| GET | `/api/v1/suites/{id}` | Get suite details |
| POST | `/api/v1/suites` | Create suite (first login flow) |
| PUT | `/api/v1/suites/{id}/private-key` | Update encrypted private key (password change) |
| POST | `/api/v1/suites/{id}/revoke` | Revoke suite |
| POST | `/api/v1/suites/{id}/reinstate` | Reinstate revoked suite (admin) |
| POST | `/api/v1/suites/compromise-recovery` | Initiate compromise recovery |
| GET | `/api/v1/migrations/{id}` | Get migration status |
| POST | `/api/v1/migrations/{id}/complete` | Finalize migration |

The API MUST never return decrypted private keys or secret values. The `private_key` field in API responses contains the AES-encrypted blob (base64).

#### Scenario: API returns encrypted blob only
- **WHEN** a client requests an EncryptionSuite via GET
- **THEN** the response MUST include the `private_key` field as the AES-encrypted base64 blob
- **AND** MUST NOT include any decrypted key material

#### Scenario: Unauthorized suite access
- **WHEN** a non-admin user attempts to access another user's EncryptionSuite
- **THEN** the API MUST return 403 Forbidden
