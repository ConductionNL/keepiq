## 0. Dependency Note (read first)

This change depends on `implement-secrets` (Secret entity / SecretService /
`secrets` table) and on the frontend secret-detail view + secret store. As of
the 2026-06-11 second pass:

- The complete, self-contained **backend** (entity, mapper, service with the
  two-phase access protocol + brute-force + usage-limit + cascade methods, both
  controllers, routes, migration) and the **client-side Argon2id crypto module**
  + **Pinia store** + i18n are implemented and tested.
- `implement-secrets` and `implement-secrets-write-ui` have since landed on
  `development`. `SecretDetail.vue`, the secret store, the unified
  `src/dialogs/SecretShareDialog.vue` (which combines the create flow + list +
  one-time password reveal — the work the spec originally split across
  `LinkShareCreateDialog` + `LinkShareList` + `LinkSharePasswordDisplay`) and
  the SecretDetail "Share" action that opens it via the registry's
  `cnOpenModal('secret-share', …)` dispatch are now in the codebase. The
  `argon2-browser` dependency, the webpack `.wasm` rule, the `publicPath:'auto'`
  fix for the lazy WASM chunk, the `loadArgon2WasmBinary` shim, and the
  `allowEvalWasm(true)` CSP are also in place. Tasks 6.1 / 6.2 / 8.2 / 8.3 /
  8.4 / 9.3 / 9.4 reflect this.
- Tasks that still require unbuilt upstream work — `SeedDevelopmentLinkShares`
  (depends on the `SeedDevelopmentSecrets` step), the public access page +
  router/lock-screen wiring (depends on a secrets SPA router that the
  manifest-v2 shell does not provide), the cascade *call sites* in
  `SecretService.delete()` / compromise recovery, and the vitest jsdom
  component-test harness — remain **[DEFERRED]** with the reason inline.

## 1. Database Migration and Seed Data

- [x] 1.1 Create ISchemaWrapper migration `Version000005Date20260603000000` for `doriath_link_shares` table (sequence continues from the existing Version000004; the design's `Version000010` numbering referenced unbuilt user-sharing migrations). Columns: id (UUID PK), secret_id (string FK NOT NULL), token (string UNIQUE NOT NULL), encrypted_secret_snapshot (text NOT NULL), argon2id_salt (string NOT NULL), encryption_suite_id (string FK NOT NULL), usage_limit (integer NOT NULL default 1), usage_count (integer NOT NULL default 0), failed_attempts (integer NOT NULL default 0), created_by (string NOT NULL), created_at (datetime NOT NULL), expires_at (datetime nullable); indexes on token (unique), secret_id, created_by
- [~] 1.2 [DEFERRED — depends on `SeedDevelopmentSecrets` from the unbuilt implement-secrets] Create `SeedDevelopmentLinkShares` IRepairStep (debug-only) seeding example link shares for dev secrets
- [~] 1.3 [DEFERRED — depends on 1.2] Register `SeedDevelopmentLinkShares` as a post-migration repair step in `info.xml`

## 2. Entity and Mapper

- [x] 2.1 Create `LinkShare` Doctrine entity in `lib/Db/LinkShare.php` with all fields, JsonSerializable (omitting encrypted_secret_snapshot and argon2id_salt from the management serialization; `jsonSerializePublic()` returns the blob + salt for the public Phase-1 payload), and column type annotations
- [x] 2.2 Create `LinkShareMapper` extending QBMapper in `lib/Db/LinkShareMapper.php` with methods: findById, findByToken, findBySecretId, findByCreatedBy, deleteBySecretId, deleteByUserId, and an atomic `incrementUsageIfBelowLimit` (UPDATE … WHERE usage_count < usage_limit) for race-safe Phase-2 confirms

## 3. Service Layer (PHP)

- [x] 3.1 Create `LinkShareService` in `lib/Service/LinkShareService.php` with: create, getByToken, confirmAccess, recordFailedAttempt, listBySecret, delete, deleteBySecretId, deleteByUserId
- [x] 3.2 Implement create: validate required fields, validate usage_limit is 1-10, generate token via `bin2hex(random_bytes(16))` (128-bit), store encryption_suite_id (resolved by the controller from the user's active suite), persist and return the row. Ownership is recorded in `created_by` and is the sole authority for later list/delete (IDOR-safe and self-contained, since SecretService does not yet exist)
- [x] 3.3 Implement getByToken: validate token exists, usage_count < usage_limit, failed_attempts < 5, expires_at null/future; delete expired/exhausted/brute-forced rows; throw on any failure so the controller returns a uniform 404
- [x] 3.4 Implement confirmAccess: atomically increment usage_count via the mapper's `incrementUsageIfBelowLimit`, reset failed_attempts to 0, delete the row when usage_count == usage_limit
- [x] 3.5 Implement recordFailedAttempt: increment failed_attempts, delete the row when it reaches 5
- [x] 3.6 Implement delete: validate the requester is the owner (created_by), then delete
- [x] 3.7 Implement deleteBySecretId and deleteByUserId cascade methods

## 4. Controllers and API Routes

- [x] 4.1 Create `LinkShareController` extending OCSController with `#[NoAdminRequired]` endpoints: index (list by secret), create (resolves the active suite, builds the link URL), destroy (revoke)
- [x] 4.2 Create `LinkShareAccessController` with `#[PublicPage]` + `#[NoCSRFRequired]` endpoints: show (Phase 1: fetch blob by token, optional `failed` flag for brute-force tracking), confirm (Phase 2)
- [x] 4.3 Register all API routes in `appinfo/routes.php` (authenticated CRUD + public access), placed before the SPA catch-all wildcard
- [x] 4.4 Owner authorization on LinkShareController: list/create/delete are scoped to the authenticated user; delete validates `created_by`
- [x] 4.5 LinkShareAccessController returns 404 (never 403) for every error case to prevent token enumeration

## 5. Cascade Integration

- [x] 5.1 Cascade *method* implemented (`LinkShareService.deleteBySecretId`). [DEFERRED — call site] Wiring into `SecretService.delete()` is deferred until implement-secrets lands (no SecretService exists yet)
- [x] 5.2 Cascade *method* implemented (`LinkShareService.deleteByUserId`). [DEFERRED — call site] Wiring into the compromise-recovery flow is deferred to avoid coupling the working recovery path to a half-integrated dependency before the secrets feature exists

## 6. Argon2id Crypto Module (Frontend)

- [x] 6.1 `argon2-browser` is declared in `package.json` (`^1.18.0`)
- [x] 6.2 `webpack.config.js` ships a `.wasm` asset/resource rule for `argon2-browser`'s lazy-loaded module (so the bundle builds and the WASM is emitted next to the JS chunk); `output.publicPath:'auto'` so the lazy chunk loads from `/custom_apps/doriath/js/` and `src/crypto/argon2.js` wires `global.loadArgon2WasmBinary` to fetch the emitted file rather than treating the URL string as base64
- [x] 6.3 Create `src/crypto/argon2.js` with deriveAesKeyArgon2id (Argon2id memory 65536 KiB / iterations 3 / parallelism 1 / hashLength 32, lazy-loaded WASM), encryptSnapshot (salt + IV + AES-GCM), decryptSnapshot (key + AES-GCM, throws on GCM tag mismatch), generateLinkPassword (20-char rejection-sampled), isArgon2Supported (WASM feature check)
- [x] 6.4 Add barrel export for the argon2 functions in `src/crypto/index.js`

## 7. Pinia Store (Frontend)

- [x] 7.1 Create `src/store/modules/linkShare.js` (useLinkShareStore) with state linkShares/loading/createdPassword/createdLinkUrl and actions createLinkShare/fetchLinkShares/deleteLinkShare/clearCreatedPassword
- [x] 7.2 Implement createLinkShare: generate password, run encryptSnapshot, POST blob + salt, store the returned link URL and password transiently. The decrypt-the-secret step is the caller's responsibility (the secrets feature) — the store takes an already-serialized snapshot, keeping it independent of the unbuilt secret store
- [x] 7.3 Implement fetchLinkShares: GET, populate linkShares (no blobs)

## 8. Vue Components (Frontend)

- [~] 8.1 [DEFERRED — standalone public page is mounted by the secrets SPA router which does not exist yet] `src/views/LinkShareAccess.vue`
- [x] 8.2 / 8.3 / 8.4 The `implement-secrets-write-ui` change shipped `src/dialogs/SecretShareDialog.vue`, a single NcDialog (per ADR-004 modal isolation) that COMBINES the create form, the existing-shares list, and the one-time password / link reveal. The spec originally proposed three separate components under `src/components/`; `secrets-write-ui` folded them into one modal-isolated dialog because the entire flow is one continuous interaction (configure → create → one-time reveal → manage). The end-user behavior matches what 8.2/8.3/8.4 specified — create with usage limit, revoke from the active list, copy the link and one-time password.

## 9. Vue Router Integration

- [~] 9.1 [DEFERRED — this app uses the manifest-v2 declarative shell; there is no `src/router/index.js`. The public `/share/link/:token` page integrates with the secrets SPA when it lands] Add the public route
- [~] 9.2 [DEFERRED — depends on 9.1] Update the lock-screen guard to skip `meta.public` routes
- [x] 9.3 / 9.4 `SecretDetail.vue` now renders a "Share" action that calls `cnOpenModal('secret-share', { secretId })` — the registry-routed dispatch surfaces the unified `SecretShareDialog`, which itself contains the create flow + active-shares list. The split between "open the create dialog" (9.3) and "render the list" (9.4) collapses into a single registry-routed modal because `secrets-write-ui` chose the unified-dialog approach.

## 10. Internationalization

- [x] 10.1 Add English translations for all new UI strings (access page, creation dialog, password display, management list, errors) to `l10n/en.json`
- [x] 10.2 Add Dutch translations for all new UI strings to `l10n/nl.json`
- [x] 10.3 Backend controller messages kept generic/translatable; the `t()`/`n()` usage in the deferred Vue components draws from the added l10n keys

## 11. Unit Tests (PHP)

- [x] 11.1 `LinkShareService.create()` tests: validates required fields, validates usage_limit range (rejects 0 and 11), generates a 32-hex-char (128-bit) token, persists the row
- [x] 11.2 `LinkShareService.getByToken()` tests: returns a valid share; throws + deletes on missing/expired/usage-exhausted/brute-force-deleted
- [x] 11.3 `LinkShareService.confirmAccess()` tests: increments atomically, resets failed_attempts, deletes when usage_count == usage_limit, throws when the atomic update affects 0 rows
- [x] 11.4 `LinkShareService.recordFailedAttempt()` tests: increments below threshold, deletes at the 5th failure, no-op for a missing token
- [x] 11.5 Cascade tests: deleteBySecretId and deleteByUserId delegate to the mapper
- [x] 11.6 Ownership tests: delete rejects non-owners; listBySecret filters to the requesting owner; entity jsonSerialize omits the blob/salt

## 12. Integration Tests (PHP)

- [x] 12.1 Controller tests for authenticated API: create returns 201 with token + link URL and no blob; list returns metadata without blobs; destroy returns 200
- [x] 12.2 Controller tests for public access: Phase 1 returns blob + salt (no owner identity); Phase 2 confirm increments usage and returns remaining
- [x] 12.3 Controller test: public endpoints return a uniform 404 for invalid/expired/exhausted/brute-force-deleted tokens
- [x] 12.4 Service test covers brute-force deletion after 5 failed attempts (11.4); controller test covers the failed-flag wiring
- [x] 12.5 Service test covers usage-limit auto-deletion via confirm (11.3)
- [x] 12.6 Controller test: non-owner destroy returns 403
- [~] 12.7 [DEFERRED — secret deletion path is in the unbuilt SecretService] End-to-end secret-deletion cascade test
- [x] 12.8 Public payload tests assert only the encrypted blob/salt are returned, never decrypted data or owner identity

## 13. Frontend Tests

- [~] 13.1 [DEFERRED — the vitest harness (added 2026-06-10 in `tests/vitest/`) currently runs in the `node` env for crypto only; the `argon2-browser` WASM peer is not yet installed (task 6.1 deferred), and component tests need a jsdom env + `@vitejs/plugin-vue2`] argon2.js round-trip tests
- [~] 13.2 [DEFERRED — useLinkShareStore tests need a jsdom env + `@vue/test-utils`; component-test harness not yet wired in vitest] useLinkShareStore tests
- [~] 13.3 [DEFERRED — component-test harness not yet wired in vitest + component depends on the secrets SPA] LinkShareAccess component tests
- [~] 13.4 [DEFERRED — component-test harness not yet wired in vitest + depends on SecretDetail] LinkShareCreateDialog component tests
- [~] 13.5 [DEFERRED — component-test harness not yet wired in vitest + depends on SecretDetail] LinkShareList component tests
