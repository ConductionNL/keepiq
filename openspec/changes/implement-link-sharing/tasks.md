## 1. Database Migration and Seed Data

- [x] 1.1 Create ISchemaWrapper migration `Version000010Date20260331000009` for `doriath_link_shares` table with columns: id (UUID PK), secret_id (string FK NOT NULL), token (string UNIQUE NOT NULL), encrypted_secret_snapshot (text NOT NULL), argon2id_salt (string NOT NULL), encryption_suite_id (string FK NOT NULL), usage_limit (integer NOT NULL default 1), usage_count (integer NOT NULL default 0), failed_attempts (integer NOT NULL default 0), created_by (string NOT NULL), created_at (datetime NOT NULL), expires_at (datetime nullable); indexes on token (unique), secret_id, created_by — also added a `blob_fetched` boolean column to implement the server-side brute-force tracking described in design.md D3 without an in-memory dependency
- [ ] 1.2 Create `SeedDevelopmentLinkShares` IRepairStep (debug-only) — DEFERRED: depends on `SeedDevelopmentSecrets` from the unbuilt implement-secrets change (no dev secrets exist to snapshot)
- [ ] 1.3 Register `SeedDevelopmentLinkShares` as post-migration repair step in `info.xml` — DEFERRED with 1.2

## 2. Entity and Mapper

- [x] 2.1 Create `LinkShare` Doctrine entity in `lib/Db/LinkShare.php` with all fields, JsonSerializable (omitting encrypted_secret_snapshot and argon2id_salt from default serialization via `jsonSerialize()`; a separate `jsonSerializeForAccess()` returns the blob + salt for the public endpoint only), and column type annotations
- [x] 2.2 Create `LinkShareMapper` extending QBMapper in `lib/Db/LinkShareMapper.php` with methods: findByToken(token), findBySecretId(secretId), findByCreatedBy(userId), deleteBySecretId(secretId), deleteByUserId(userId) — plus findById and the race-safe `incrementUsageCountIfBelowLimit()` (atomic `UPDATE WHERE usage_count < usage_limit`, design.md D3)

## 3. Service Layer (PHP)

- [x] 3.1 Create `LinkShareService` in `lib/Service/LinkShareService.php` with methods: create, getByToken, confirmAccess, recordFailedAttempt, listBySecret, delete, deleteBySecretId, deleteByUserId
- [x] 3.2 Implement create method: validate usage_limit is 1-10, generate token via `bin2hex(random_bytes(16))`, store encryption_suite_id from the owner's active suite (via EncryptionSuiteService), create and return the LinkShare row. NOTE: secret-ownership validation is recorded via `created_by` (the session user from the controller); the `SecretService` cross-check is deferred until implement-secrets ships a `SecretService`
- [x] 3.3 Implement getByToken: validate token exists, usage_count < usage_limit, failed_attempts < 5, expires_at null/future; increment failed_attempts on repeat un-confirmed blob fetches; throw NotFoundException on any failure
- [x] 3.4 Implement confirmAccess: atomically increment usage_count (`UPDATE WHERE usage_count < usage_limit`), reset failed_attempts, delete when usage_count reaches usage_limit
- [x] 3.5 Implement recordFailedAttempt: increment failed_attempts, delete when it reaches 5
- [x] 3.6 Implement delete: validate the current user owns (created) the link share, then delete
- [x] 3.7 Implement deleteBySecretId and deleteByUserId cascade methods

## 4. Controllers and API Routes

- [x] 4.1 Create `LinkShareController` extending OCSController with authenticated endpoints: index (list by secret), create, destroy (revoke)
- [x] 4.2 Create `LinkShareAccessController` with `#[PublicPage]` + `#[NoCSRFRequired]` endpoints: show (Phase 1), confirm (Phase 2)
- [x] 4.3 Register all API routes in `appinfo/routes.php`: authenticated CRUD under `/api/v1/secrets/{secretId}/link-shares` and `/api/v1/link-shares/{id}`, public under `/api/v1/public/link-shares/{token}` and `.../confirm`
- [x] 4.4 Add owner authorization checks on LinkShareController: user must own (have created) the link share to list or delete; create records the session user as owner
- [x] 4.5 Ensure LinkShareAccessController public endpoints return 404 (not 403) for all error cases to prevent token enumeration

## 5. Cascade Integration

- [ ] 5.1 Add link share cascade deletion to SecretService.delete() — DEFERRED: no `SecretService` exists yet (implement-secrets unbuilt). `LinkShareService.deleteBySecretId()` is implemented and ready to wire
- [ ] 5.2 Add link share cascade deletion to compromise recovery — DEFERRED: `LinkShareService.deleteByUserId()` is implemented; the wiring into the recovery flow needs the user-scoped cascade hook from implement-secrets

## 6. Argon2id Crypto Module (Frontend)

- [x] 6.1 Add `argon2-browser` npm dependency to `package.json`
- [x] 6.2 Configure webpack to handle WASM files from argon2-browser (file-loader rule for .wasm; added file-loader devDependency)
- [x] 6.3 Create `src/crypto/argon2.js` with deriveAesKeyArgon2id (mem 65536, iter 3, par 1, hashLen 32), encryptSnapshot, decryptSnapshot, plus isArgon2Supported (WASM check) and generateLinkPassword helper
- [x] 6.4 Add barrel export for argon2 functions in `src/crypto/index.js`

## 7. Pinia Store (Frontend)

- [ ] 7.1 Create `src/store/modules/linkShare.js` — DEFERRED: the secret-decryption path it calls (session CryptoKey + rsaDecrypt of a Secret) requires the unbuilt implement-secrets Secret store/entity
- [ ] 7.2 Implement createLinkShare action — DEFERRED with 7.1 (needs Secret plaintext to snapshot)
- [ ] 7.3 Implement fetchLinkShares action — DEFERRED with 7.1

## 8. Vue Components (Frontend)

- [ ] 8.1 Create `src/views/LinkShareAccess.vue` — DEFERRED: the app has no `src/router/index.js` yet (the public route foundation lands with the secrets/router work); the argon2 module it would consume is shipped and ready
- [ ] 8.2 Create `src/components/LinkShareCreateDialog.vue` — DEFERRED with 8.1
- [ ] 8.3 Create `src/components/LinkShareList.vue` — DEFERRED with 8.1
- [ ] 8.4 Create `src/components/LinkSharePasswordDisplay.vue` — DEFERRED with 8.1

## 9. Vue Router Integration

- [ ] 9.1 Add route `/share/link/:token` — DEFERRED: no `src/router/index.js` exists yet
- [ ] 9.2 Update the lock screen `beforeEach` guard to skip `meta.public` routes — DEFERRED with 9.1
- [ ] 9.3 Integrate LinkShareCreateDialog into SecretDetail view — DEFERRED: no SecretDetail view (implement-secrets unbuilt)
- [ ] 9.4 Integrate LinkShareList into SecretDetail view — DEFERRED with 9.3

## 10. Internationalization

- [x] 10.1 Add English translations for all new UI strings (access page, creation dialog, password display, management list, errors) to `l10n/en.json`
- [x] 10.2 Add Dutch translations for all new UI strings to `l10n/nl.json` (59/59 key parity)
- [x] 10.3 Use `t()` / `n()` translation functions in Vue components and PHP controllers — the i18n string keys are seeded in l10n; PHP API `message` fields follow the existing controller convention (plain strings, not IL10N-wrapped, matching the rest of the codebase). Consuming components are deferred with §8

## 11. Unit Tests (PHP)

- [x] 11.1 Unit tests for `create()`: validates usage_limit range (1-10), rejects 0/11/negative/null, rejects empty snapshot, generates a 128-bit hex token, stores the active suite
- [x] 11.2 Unit tests for `getByToken()`: returns valid share, throws NotFound when missing/expired/usage-exhausted/brute-force-deleted, deletes expired
- [x] 11.3 Unit tests for `confirmAccess()`: increments usage_count, deletes at limit, throws when already exhausted
- [x] 11.4 Unit tests for `recordFailedAttempt()`: increments, deletes at 5
- [x] 11.5 Unit tests for cascade deletion: deleteBySecretId / deleteByUserId delegate to the mapper
- [x] 11.6 Unit tests for ownership validation: delete rejects non-owner, listBySecret filters to owner

## 12. Integration Tests (PHP)

- [ ] 12.1 Integration tests for authenticated link share API — DEFERRED: require a live DB + Secret fixtures (implement-secrets) for an end-to-end run; the unit suite covers the service contract
- [ ] 12.2 Integration tests for public access API — DEFERRED with 12.1
- [ ] 12.3 Integration test: public endpoint 404 for all error cases — DEFERRED with 12.1 (controller logic unit-asserted via getByToken/confirmAccess throwing DoesNotExist)
- [ ] 12.4 Integration test: brute-force deletes after 5 failed Phase-1 calls — DEFERRED with 12.1 (logic unit-tested in 11.2)
- [ ] 12.5 Integration test: usage-limit auto-delete — DEFERRED with 12.1 (logic unit-tested in 11.3)
- [ ] 12.6 Integration test: non-owner 403 — DEFERRED with 12.1 (logic unit-tested in 11.6)
- [ ] 12.7 Integration test: secret-deletion cascade — DEFERRED with 12.1 (needs SecretService wiring, §5.1)
- [ ] 12.8 Integration test: public endpoints never return decrypted data — DEFERRED with 12.1 (entity `jsonSerializeForAccess` only ever returns the encrypted blob)

## 13. Frontend Tests

- [ ] 13.1 Unit tests for `src/crypto/argon2.js` — DEFERRED: the repo has no JS unit-test runner (only Playwright e2e); adding one is out of this change's scope
- [ ] 13.2 Unit tests for useLinkShareStore — DEFERRED with 13.1 (and store deferred, §7)
- [ ] 13.3 Component tests for LinkShareAccess — DEFERRED with 13.1 (component deferred, §8)
- [ ] 13.4 Component tests for LinkShareCreateDialog — DEFERRED with 13.1
- [ ] 13.5 Component tests for LinkShareList — DEFERRED with 13.1
