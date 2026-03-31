## 1. Database Migration and Seed Data

- [ ] 1.1 Create ISchemaWrapper migration `Version000007Date20260331000006` for `doriath_link_shares` table with columns: id (UUID PK), secret_id (string FK NOT NULL), token (string UNIQUE NOT NULL), encrypted_secret_snapshot (text NOT NULL), argon2id_salt (string NOT NULL), encryption_suite_id (string FK NOT NULL), usage_limit (integer NOT NULL default 1), usage_count (integer NOT NULL default 0), failed_attempts (integer NOT NULL default 0), created_by (string NOT NULL), created_at (datetime NOT NULL), expires_at (datetime nullable); indexes on token (unique), secret_id, created_by
- [ ] 1.2 Create `SeedDevelopmentLinkShares` IRepairStep (debug-only) that creates example link shares for dev secrets (GitHub with limit 3, AWS with limit 1 + expiry, Production DB with limit 5 + 2 used), plus one expired and one usage-exhausted link share for edge-case testing; encrypts snapshots using Argon2id with known test passwords
- [ ] 1.3 Register `SeedDevelopmentLinkShares` as post-migration repair step in `info.xml` (debug-only condition, after SeedDevelopmentSecrets)

## 2. Entity and Mapper

- [ ] 2.1 Create `LinkShare` Doctrine entity in `lib/Db/LinkShare.php` with all fields, JsonSerializable (omitting encrypted_secret_snapshot and argon2id_salt from default serialization), and column type annotations
- [ ] 2.2 Create `LinkShareMapper` extending QBMapper in `lib/Db/LinkShareMapper.php` with methods: findByToken(token), findBySecretId(secretId), findByCreatedBy(userId), deleteBySecretId(secretId), deleteByUserId(userId)

## 3. Service Layer (PHP)

- [ ] 3.1 Create `LinkShareService` in `lib/Service/LinkShareService.php` with methods: create(secretId, encryptedSnapshot, salt, usageLimit, expiresAt, userId), getByToken(token), confirmAccess(token), recordFailedAttempt(token), listBySecret(secretId, userId), delete(id, userId), deleteBySecretId(secretId), deleteByUserId(userId)
- [ ] 3.2 Implement create method: validate user owns the secret via SecretService, validate usage_limit is 1-10, generate token via `bin2hex(random_bytes(16))`, store encryption_suite_id from user's active suite, create and return the LinkShare row
- [ ] 3.3 Implement getByToken method: validate token exists, usage_count < usage_limit, failed_attempts < 5, expires_at is null or in the future; increment failed_attempts (for all calls after the first without a preceding confirm); throw NotFoundException on any validation failure
- [ ] 3.4 Implement confirmAccess method: atomically increment usage_count (using `UPDATE WHERE usage_count < usage_limit`), reset failed_attempts to 0, delete the link share if usage_count equals usage_limit after increment
- [ ] 3.5 Implement recordFailedAttempt method: increment failed_attempts, delete the link share if failed_attempts reaches 5
- [ ] 3.6 Implement delete method: validate the current user owns the secret associated with the link share, then delete the row
- [ ] 3.7 Implement deleteBySecretId and deleteByUserId cascade methods for secret deletion and compromise recovery

## 4. Controllers and API Routes

- [ ] 4.1 Create `LinkShareController` extending OCSController in `lib/Controller/LinkShareController.php` with authenticated endpoints: index (list by secret), create, destroy (revoke)
- [ ] 4.2 Create `LinkShareAccessController` in `lib/Controller/LinkShareAccessController.php` with `#[PublicPage]` annotated endpoints: show (Phase 1: fetch blob by token), confirm (Phase 2: confirm decryption)
- [ ] 4.3 Register all API routes in `appinfo/routes.php`: authenticated CRUD under `/api/v1/secrets/{secretId}/link-shares` and `/api/v1/link-shares/{id}`, public access under `/api/v1/public/link-shares/{token}` and `/api/v1/public/link-shares/{token}/confirm`
- [ ] 4.4 Add owner authorization checks on LinkShareController: user must own the secret to create, list, or delete link shares
- [ ] 4.5 Ensure LinkShareAccessController public endpoints return 404 (not 403) for all error cases to prevent token enumeration

## 5. Cascade Integration

- [ ] 5.1 Add link share cascade deletion to SecretService.delete(): call LinkShareService.deleteBySecretId() when a secret is deleted
- [ ] 5.2 Add link share cascade deletion to MigrationService.completeMigration() (or compromise recovery flow): call LinkShareService.deleteByUserId() when a user initiates compromise recovery

## 6. Argon2id Crypto Module (Frontend)

- [ ] 6.1 Add `argon2-browser` npm dependency to `package.json`
- [ ] 6.2 Configure webpack to handle WASM files from argon2-browser (file-loader rule for .wasm)
- [ ] 6.3 Create `src/crypto/argon2.js` with functions: deriveAesKeyArgon2id(password, salt) using fixed parameters (memory: 65536, iterations: 3, parallelism: 1, hashLength: 32), encryptSnapshot(jsonString, password) generating salt + IV + AES-GCM encrypt, decryptSnapshot(base64Blob, base64Salt, password) deriving key + AES-GCM decrypt
- [ ] 6.4 Add barrel export for argon2 functions in `src/crypto/index.js`

## 7. Pinia Store (Frontend)

- [ ] 7.1 Create `src/store/modules/linkShare.js` (useLinkShareStore) with state: linkShares (array), loading, createdPassword (string, transient), createdLinkUrl (string, transient); actions: createLinkShare(secretId, usageLimit, expiresAt), fetchLinkShares(secretId), deleteLinkShare(id), clearCreatedPassword()
- [ ] 7.2 Implement createLinkShare action: decrypt secret via session CryptoKey and rsaDecrypt, serialize plaintext fields as JSON, generate random password (20 chars via crypto.getRandomValues), call encryptSnapshot from argon2 module, POST to API, store returned link URL and password in transient state
- [ ] 7.3 Implement fetchLinkShares action: GET from API, populate linkShares array (no blobs in response)

## 8. Vue Components (Frontend)

- [ ] 8.1 Create `src/views/LinkShareAccess.vue` as standalone public page: Doriath branding, password input, submit button with loading spinner during Argon2id, success state showing decrypted secret fields in read-only card, error state with retry, "link expired" state
- [ ] 8.2 Create `src/components/LinkShareCreateDialog.vue` using NcDialog: usage limit selector (1-10, default 1), optional expiry date picker, submit button disabled during encryption, transitions to password display on success
- [ ] 8.3 Create `src/components/LinkShareList.vue` using CnDataTable: columns for token (truncated to first 8 chars), usage (count/limit), created date, expiry date, delete button per row
- [ ] 8.4 Create `src/components/LinkSharePasswordDisplay.vue` using NcNoteCard: shows generated link URL and password with copy buttons for each, warning that password is shown once only

## 9. Vue Router Integration

- [ ] 9.1 Add route `/share/link/:token` to `src/router/index.js` mapping to LinkShareAccess component, with `meta: { public: true }` to exclude from lock screen guard
- [ ] 9.2 Update the lock screen `beforeEach` navigation guard to skip routes with `meta.public === true`
- [ ] 9.3 Integrate LinkShareCreateDialog into SecretDetail view: add "Share via link" button that opens the dialog
- [ ] 9.4 Integrate LinkShareList into SecretDetail view (CnObjectSidebar or dedicated tab): show active link shares for the current secret

## 10. Internationalization

- [ ] 10.1 Add English translations for all new UI strings: link share access page (password prompt, error messages, expired message), creation dialog (usage limit label, expiry label, submit button), password display (copy instructions, warning), management list (column headers, delete confirmation), and error messages
- [ ] 10.2 Add Dutch translations for all new UI strings
- [ ] 10.3 Use `t()` / `n()` translation functions in all Vue components and PHP controllers

## 11. Unit Tests (PHP)

- [ ] 11.1 Write unit tests for `LinkShareService.create()`: validates ownership, validates usage_limit range (1-10), rejects null/0/11, generates token with correct entropy, stores encrypted snapshot
- [ ] 11.2 Write unit tests for `LinkShareService.getByToken()`: returns link share when valid, throws NotFoundException when token missing/expired/usage exhausted/brute-force deleted
- [ ] 11.3 Write unit tests for `LinkShareService.confirmAccess()`: increments usage_count atomically, resets failed_attempts, deletes when usage_count equals usage_limit
- [ ] 11.4 Write unit tests for `LinkShareService.recordFailedAttempt()`: increments failed_attempts, deletes when failed_attempts reaches 5
- [ ] 11.5 Write unit tests for cascade deletion: deleteBySecretId removes all link shares for a secret, deleteByUserId removes all link shares for a user
- [ ] 11.6 Write unit tests for ownership validation: create and delete reject non-owner requests

## 12. Integration Tests (PHP)

- [ ] 12.1 Write integration tests for authenticated link share API: create (returns token, no password), list (returns metadata without blobs), delete (revoke)
- [ ] 12.2 Write integration tests for public access API: Phase 1 returns blob + salt for valid token, Phase 2 confirm increments usage_count and resets failed_attempts
- [ ] 12.3 Write integration test: public endpoint returns 404 for invalid token, expired link, usage-exhausted link, and brute-force-deleted link (consistent error for all cases)
- [ ] 12.4 Write integration test: brute-force protection deletes link share after 5 failed Phase 1 calls without confirm
- [ ] 12.5 Write integration test: usage limit enforcement auto-deletes link share when usage_count reaches usage_limit via confirm endpoint
- [ ] 12.6 Write integration test: non-owner cannot create or delete link shares for another user's secret (403)
- [ ] 12.7 Write integration test: secret deletion cascades to all associated link shares
- [ ] 12.8 Write integration test: public endpoints never return decrypted secret data (only encrypted blobs)

## 13. Frontend Tests

- [ ] 13.1 Write unit tests for `src/crypto/argon2.js`: deriveAesKeyArgon2id produces consistent output for same password+salt, encryptSnapshot/decryptSnapshot round-trip, decryptSnapshot with wrong password throws (GCM tag mismatch)
- [ ] 13.2 Write unit tests for useLinkShareStore: createLinkShare sets createdPassword and createdLinkUrl, fetchLinkShares populates array, deleteLinkShare removes from array, clearCreatedPassword nulls transient state
- [ ] 13.3 Write component tests for LinkShareAccess: renders password input, shows spinner during Argon2id, shows decrypted content on success, shows error on failure, shows expired message for 404
- [ ] 13.4 Write component tests for LinkShareCreateDialog: renders usage limit selector with range 1-10, default 1; disables submit during encryption; shows LinkSharePasswordDisplay on success
- [ ] 13.5 Write component tests for LinkShareList: renders table with truncated tokens, usage counts, delete buttons; calls deleteLinkShare on delete click
