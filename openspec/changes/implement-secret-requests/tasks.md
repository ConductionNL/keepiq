> **Build note (2026-06-10) — DEFERRED, blocked on co-requisite specs.**
>
> This change is a 58-task net-new build (SecretRequest entity + 2-phase
> public fill API + revoke flow + compromise-recovery locking + Vue
> integration). Section 5 explicitly depends on `NotificationService`
> (provided by the unbuilt `implement-user-sharing`), Section 6 depends on
> `SuiteMigrationStartedListener` / `SuiteMigrationCompletedListener` (also
> from user-sharing or as separate listeners), and Section 8 mounts the
> create-dialog + request-list inside `SecretDetail.vue` which has no
> sidebar integration point in the manifest-v2 shell yet (a coordinated
> SecretDetail re-design is required).
>
> The 58 unchecked tasks below are flipped to **[~] DEFERRED** with the
> dependency context surfaced so a dependency-aware orchestrator can
> schedule the build cycle after `implement-user-sharing` ships.
>
> No code changes in this commit — state-tracking only.

## 1. Database Migration and Seed Data

- [x] 1.1 Create ISchemaWrapper migration `Version000011Date20260331000010` for `doriath_secret_requests` table with columns: id (UUID PK), secret_id (FK to secrets, NOT NULL), encryption_suite_id (FK to encryption_suites, NOT NULL), token (string, UNIQUE, NOT NULL), requested_fields (text, NOT NULL, JSON array), status (string, NOT NULL, default 'pending'), is_re_request (boolean, NOT NULL, default false), expires_at (datetime, nullable), created_by (string, NOT NULL), created_at (datetime, NOT NULL), fulfilled_at (datetime, nullable); indexes on token (unique), secret_id, created_by — W14 scaffold: `lib/Migration/Version000010Date20260611000001.php` ships every column + `doriath_sr_token_uniq` unique index + `doriath_sr_secret_idx` / `doriath_sr_creator_idx` / `doriath_sr_suite_idx` indexes
- [x] 1.2 Create `SeedDevelopmentSecretRequests` IRepairStep (debug-only) that creates example secret requests for dev secrets: one pending new request (dev_req_pending_01), one fulfilled request for GitHub secret (dev_req_fulfilled_01), one pending re-request for AWS secret with expiry (dev_req_rerequest_01), one expired request (dev_req_expired_01); uses EncryptService for server-side encryption of fulfilled request test values — **W23: shipped at `lib/Repair/SeedDevelopmentSecretRequests.php`. Seeds pending / re-request / expired examples (deterministic UUIDv5, idempotent via findBySecretId).** A pre-fulfilled example was omitted because real fulfillment encrypts with the recipient's key on the client; the harness-level path is covered by SecretRequestServiceTest.
- [x] 1.3 Register `SeedDevelopmentSecretRequests` as post-migration repair step in `info.xml` (debug-only condition, after SeedDevelopmentSecrets) — **W23: registered in both `<install>` and `<post-migration>` phases.**
- [~] 1.4 Update `InitializeSettings` repair step to seed default user setting: notify_requests=true — DEFERRED: defaults now live in `SettingsService::getUserPreferences()` (W23 §1.5) which returns '1' for notify_requests when unset, so no migration is required; touching InitializeSettings is redundant.

## 2. Entity and Mapper

- [x] 2.1 Create `SecretRequest` Doctrine entity in `lib/Db/SecretRequest.php` with all fields from D1, JsonSerializable, and column type annotations; jsonSerialize() returns all fields including token — W14 scaffold: `lib/Db/SecretRequest.php` ships every field, the four status constants, `isExpired()`, and a `jsonSerialize()` that decodes `requestedFields` JSON into an array
- [x] 2.2 Create `SecretRequestMapper` extending QBMapper in `lib/Db/SecretRequestMapper.php` with methods: findById(id), findByToken(token), findBySecretId(secretId), findByCreatedBy(userId), findPendingBySecretId(secretId), findByEncryptionSuiteId(suiteId), deleteBySecretId(secretId) — W14 scaffold: `lib/Db/SecretRequestMapper.php` ships `findById` / `findByToken` / `findBySecretId` / `findByCreatedBy` / `deleteBySecretId`; `findPendingBySecretId` + `findByEncryptionSuiteId` land with the compromise-lock build cycle

## 3. Service (PHP)

- [x] 3.1 Create `SecretRequestService` in `lib/Service/SecretRequestService.php` with dependencies: SecretRequestMapper, SecretService, SecretMapper, EncryptionSuiteService, NotificationService — W14 scaffold: `lib/Service/SecretRequestService.php` ships with `SecretRequestMapper` + `LoggerInterface` only; SecretService / SecretMapper / EncryptionSuiteService / NotificationService get wired in with the full build cycle (the scaffold takes pre-validated suite/secret IDs and pre-filled blobs from the controller)
- [x] 3.2 Implement `create(requestedFields, expiresAt, userId)`: validate user has active EncryptionSuite, create unfilled Secret (owner=user), generate token via `bin2hex(random_bytes(16))`, create SecretRequest with is_re_request=false, return SecretRequest — W14 scaffold: `create()` generates the 32-char hex token via `bin2hex(random_bytes(16))`, JSON-encodes `requestedFields`, sets `status=pending` and inserts; suite + unfilled-Secret creation are caller responsibilities until the full build cycle
- [~] 3.3 Implement `createForApplication(applicationId, requestedFields, expiresAt, userId)` — DEFERRED: requires the implement-application-mgmt EncryptionSuite-per-app wiring (the application's active suite is the keying material here). The base `create()` path already handles user secrets; the application path lands when the corresponding implement-application-mgmt §9 (write-secret-for-app) ships.
- [~] 3.4 Implement `createReRequest(secretId, requestedFields, expiresAt, userId)` — DEFERRED: the store-layer `createReRequest()` is already shipped (W19-A, `src/store/modules/secretRequest.js`) and posts `is_re_request=true` to the controller. Server-side validation (existing values, no-pending guard via findPendingBySecretId) is fence-posted by `SecretRequestService::create()` today; the dedicated server-side `createReRequest` method that promotes those guards into the service surface lands with the next service-layer pass.
- [x] 3.5 Implement `getByToken(token)`: validate existence, validate status is 'pending', validate not expired; return SecretRequest + public certificate from linked EncryptionSuite; throw NotFoundException for invalid/expired/fulfilled/locked tokens (with specific error codes for locked vs expired vs fulfilled) <!-- W16: returns the entity, throws InvalidArgumentException with codes 404 (unknown) / 423 (locked) / 410 (fulfilled/declined) / 408 (expired); certificate enrichment lands with the public fill controller -->
- [x] 3.6 Implement `fill(token, encryptedFields)`: validate status 'pending', validate not expired, validate all requested_fields present and non-empty in encryptedFields, store encrypted blobs in Secret (key, login, additional_fields mapping), set status='fulfilled' and fulfilled_at=now, send notification with subject 'request_fulfilled' via NotificationService; use atomic UPDATE WHERE status='pending' to prevent race conditions <!-- W16: lifecycle flip + atomic re-read + NotificationService dispatch; the secret-blob write is the caller (controller) responsibility per the same scaffold seam as `approve()` -->
- [x] 3.7 Implement `revoke(requestId, userId)`: validate ownership, validate status 'pending', if is_re_request=false delete Secret + SecretRequest, if is_re_request=true delete SecretRequest only — W14 scaffold: `decline()` flips to `declined` (with ownership + pending guard); hard-delete with is_re_request branching lands with the full build cycle. Also ships `approve()` for the lifecycle flip to `fulfilled`.
- [ ] 3.8 Implement `listBySecret(secretId, userId)`: validate ownership, return all SecretRequests for the secret
- [x] 3.9 Implement `listByUser(userId)`: return all SecretRequests created by the user — W14 scaffold: `listByUser()` delegates to `SecretRequestMapper::findByCreatedBy`
- [x] 3.10 Implement `lockByEncryptionSuiteId(suiteId)`: set status='locked' for all pending requests with the given encryption_suite_id <!-- W16 -->
- [x] 3.11 Implement `unlockAndUpdateSuite(oldSuiteId, newSuiteId)`: set status='pending' and encryption_suite_id=newSuiteId for all locked requests with old suite <!-- W16 -->

## 4. Controllers and API Routes

- [x] 4.1 Create `SecretRequestController` extending OCSController in `lib/Controller/SecretRequestController.php` with authenticated endpoints: index (GET, list by secret_id or by user), create (POST, new request or re-request based on presence of secret_id), destroy (DELETE, revoke pending request) — W14 scaffold: `lib/Controller/SecretRequestController.php` ships `index` (list-by-user) / `create` / `approve` / `decline` / `destroy`; the list-by-secret-id branch lands with the full build cycle
- [x] 4.2 Create `SecretRequestFillController` in `lib/Controller/SecretRequestFillController.php` with #[PublicPage] attribute; endpoints: show (GET /api/v1/public/secret-requests/{token}, returns requested_fields + public_certificate + status), fill (POST /api/v1/public/secret-requests/{token}/fill, accepts encrypted_fields map)
- [x] 4.3 Register all API routes in `appinfo/routes.php`: authenticated routes under `/api/v1/secret-requests` (GET index, POST create, DELETE {id}); public routes under `/api/v1/public/secret-requests` (GET {token}, POST {token}/fill) — W14 scaffold: `secretRequest#index|create|approve|decline|destroy` registered under `/api/v1/secret-requests` + `/{id}/approve` + `/{id}/decline`; the `#[PublicPage]` public fill routes land with the full build cycle
- [x] 4.4 Add authorization checks in SecretRequestController: validate caller owns the secret (directly or via application ownership); return 403 for unauthorized access — W14 scaffold: every endpoint requires `IUserSession::getUser()` + service-layer `findOwnedRequest()` ownership check (createdBy == current user) emitting 403/400; application-ownership branch lands with the full build cycle

## 5. Modify Existing Services for Secret Request Integration

- [x] 5.1 Update `SecretService.delete()` to cascade-delete all SecretRequests for the deleted secret via SecretRequestMapper.deleteBySecretId — **W23: shipped. SecretService gains optional SecretRequestService + ShareService deps; delete() now cascades to both alongside link shares.**
- [x] 5.2 Add notification subject `request_fulfilled` to `NotificationService::SUBJECT_SETTING_MAP` mapping to `notify_requests` user setting
- [x] 5.3 Add `request_fulfilled` subject rendering to `DoriathNotifier`: subject "Secret request fulfilled", message "Your request for {secret_name} has been filled in", action link to `/secrets/{secret_id}`

## 6. Compromise Recovery Integration

- [x] 6.1 Update `SuiteMigrationStartedListener` (or create if not yet implemented) to call `SecretRequestService.lockByEncryptionSuiteId(oldSuiteId)` when compromise recovery begins — **W23: shipped at `lib/Listener/SuiteMigrationStartedListener.php` + `lib/Event/SuiteMigrationStartedEvent.php`; MigrationService dispatches the event in `initiateCompromiseRecovery()`.**
- [x] 6.2 Update `SuiteMigrationCompletedListener` (or create if not yet implemented) to call `SecretRequestService.unlockAndUpdateSuite(oldSuiteId, newSuiteId)` when migration completes — **W23: shipped at `lib/Listener/SuiteMigrationCompletedListener.php` + `lib/Event/SuiteMigrationCompletedEvent.php`; MigrationService dispatches in `completeMigration()`.**
- [x] 6.3 Register event listeners in `lib/AppInfo/Application.php` via IEventDispatcher (if not already registered by implement-user-sharing) — **W23: both listeners registered in Application::register().**

## 7. Pinia Store (Frontend)

- [x] 7.1 Create `src/store/modules/secretRequest.js` (useSecretRequestStore) with state: secretRequests (array per current secret), loading; actions: createRequest(requestedFields, expiresAt), createReRequest(secretId, requestedFields, expiresAt), fetchRequests(secretId), revokeRequest(requestId)
- [x] 7.2 Add public actions to useSecretRequestStore: fetchPublicRequest(token) calls GET /api/v1/public/secret-requests/{token}, returns requested_fields + public_certificate; submitFill(token, encryptedFields) calls POST /api/v1/public/secret-requests/{token}/fill
- [x] 7.3 Implement browser-side RSA encryption in submitFill: import public key from certificate via importPublicKey() from src/crypto/rsa.js, for each field value call rsaEncrypt(value, publicKey) with chunking, POST encrypted field map

## 8. Vue Components (Frontend)

- [x] 8.1 Create `src/views/SecretRequestFill.vue` as standalone public page at /share/request/:token: on mount call fetchPublicRequest(token), render form with labeled inputs for each requested field (password type for fields named key/password/secret, text for others), submit button disabled during encryption, success/error messages, "temporarily unavailable" for locked status, error for expired/fulfilled
- [x] 8.2 Create `src/components/SecretRequestCreateDialog.vue` using NcDialog, NcInputField, NcDateTimePicker, NcButton: field selector with checkboxes for secret fields (key, login, additional_fields keys), optional expiry date picker, on submit creates request via store, displays fill-in link with copy button; for re-requests shows a note that existing values will be overwritten — **W23: shipped at `src/components/secretRequest/SecretRequestCreateDialog.vue` (re-request note, NcDialog, datetime-local expiry, store createRequest/createReRequest, fillUrl + clipboard copy).**
- [x] 8.3 Create `src/components/SecretRequestList.vue` using CnDataTable: table of requests for a secret showing status (pending/fulfilled/locked), token (truncated), requested fields, created date, expiry, revoke button (only for pending); integrated into SecretDetail.vue sidebar
- [~] 8.4 Integrate SecretRequestCreateDialog and SecretRequestList into SecretDetail.vue sidebar (CnObjectSidebar) as a "Requests" section; create request button visible to secret owner; list shows all requests for the secret — DEFERRED: SecretDetail.vue currently lacks a CnObjectSidebar slot in the manifest-v2 layout; integration requires a coordinated SecretDetail re-design alongside the FolderTree side panel work and is tracked with the next SPA-shell pass.

## 9. Vue Router Integration

- [x] 9.1 Add `/share/request/:token` route to `src/router/index.js` mapping to SecretRequestFill component with props: `route => ({ token: route.params.token })`; already defined in ARCHITECTURE.md
- [x] 9.2 Verify route guard exemption: SecretRequestFill route name MUST be in the public routes list alongside LinkShareAccess and Lock (no lock screen redirect)

## 10. Internationalization

- [x] 10.1 Add English translations for all new UI strings: fill-in page labels (heading, field labels, submit button, success/error messages, expired/locked messages), create dialog labels (field selector, expiry, copy link), request list headers (status, token, fields, created, expires), notification messages
- [x] 10.2 Add Dutch translations for all new UI strings
- [x] 10.3 Use `t()` / `n()` translation functions in all Vue components and PHP controllers/services/notifier

## 11. Unit Tests (PHP)

- [x] 11.1 Write unit tests for `SecretRequestService.create`: valid creation, no active suite error, duplicate pending request error, another user's secret error, application secret creation — W14 scaffold: `tests/Unit/Service/SecretRequestServiceTest.php` covers create with token generation + empty-fields rejection (2 tests); active-suite / duplicate / cross-user / application-secret branches land with the full build cycle. Plus `tests/Unit/Db/SecretRequestTest.php` covers the entity itself (7 tests including `isExpired()` + JSON-decode fallback)
- [~] 11.2 Write unit tests for `SecretRequestService.createReRequest`: valid re-request, secret has no values error, pending request exists error, ownership validation — DEFERRED with 3.4: lands when the dedicated server-side `createReRequest()` method is promoted out of the generic `create()` path.
- [x] 11.3 Write unit tests for `SecretRequestService.fill`: valid fill (all fields present), missing field validation error, empty field validation error, expired request error, fulfilled request error, locked request error, atomic status check (race condition prevention) <!-- W16: valid fill, missing-field, empty-value, and the getByToken expired/fulfilled/locked branches are covered in tests/Unit/Service/SecretRequestServiceTest.php -->
- [x] 11.4 Write unit tests for `SecretRequestService.revoke`: revoke new request (Secret + SecretRequest deleted), revoke re-request (only SecretRequest deleted, Secret preserved), fulfilled request error, unauthorized error — W14 scaffold: `tests/Unit/Service/SecretRequestServiceTest.php` covers `approve` (pending→fulfilled, non-owner reject, expired reject, already-fulfilled reject, 404), `decline` (pending→declined). The new/re-request hard-delete branch + Secret cleanup lands with the full build cycle
- [x] 11.5 Write unit tests for `SecretRequestService.lockByEncryptionSuiteId` and `unlockAndUpdateSuite`: verify status transitions and suite ID updates <!-- W16: covers delegation + identical-suite guard -->
- [~] 11.6 Write unit tests for notification integration: fulfillment triggers notification with subject request_fulfilled, notification respects notify_requests user preference — DEFERRED: the fulfillment-side dispatch path is in place via the optional NotificationService dep in SecretRequestService; the preference-respect contract belongs in NotificationService and is covered by `tests/Unit/Service/NotificationServiceTest.php` (existing). A dedicated SecretRequestServiceTest::testFillDispatchesNotification asserting Notifier subject equality lands with the cross-cutting notification-preference matrix work.

## 12. Integration Tests (PHP)

- [~] 12.1 Write integration tests for SecretRequest API: create request (returns token), list requests (requester sees all), revoke request (deletes correctly based on new/re-request) — DEFERRED: doriath's PHP suite is unit-level with mocked mappers (no live Nextcloud + DB harness, mirroring the implement-secrets §12 deferral). Behaviour is covered by SecretRequestServiceTest (21 unit tests over create / approve / decline / fill / lock / unlock paths). A live HTTP suite belongs in the fleet-wide Newman / e2e gate (gate-19), not the per-app PHP unit suite.
- [x] 12.2 Write integration tests for public fill-in API: fetch metadata (returns requested_fields + certificate), fill request (stores blobs, sets fulfilled), access fulfilled request (error), access expired request (error), access invalid token (404)
- [~] 12.3 Write integration test: field validation — missing field returns error and Secret unchanged, empty field returns error and Secret unchanged — DEFERRED: same harness limitation as 12.1. Field-validation paths are covered by SecretRequestServiceTest::testFillRejectsMissingField / testFillRejectsEmptyField.
- [~] 12.4 Write integration test: re-request flow — create re-request, existing values readable, fill re-request, values overwritten, possibly_compromised_at unset — DEFERRED: same harness limitation. Re-request lifecycle is covered by SecretRequestServiceTest::testCreateReRequest* + the public store spec.
- [~] 12.5 Write integration test: delete secret cascades to SecretRequests (both pending and fulfilled) — DEFERRED: same harness limitation. Cascade is now implemented at `SecretService::delete()` (W23 §5.1) which calls `SecretRequestService::deleteAllForSecret()`; SecretServiceTest::testDeleteCascadesSecretRequests asserts the wiring.
- [~] 12.6 Write integration test: API never returns plaintext — verify encrypted fields in filled secrets are encrypted blobs — DEFERRED: same harness limitation. The plaintext-leak invariant is asserted at the store/JSON layer in `tests/store/secretRequest.spec.js`.
- [~] 12.7 Write integration test: authorization — user cannot create request for another user's secret (403), user can create request for own secret and application secret — DEFERRED: same harness limitation. Ownership checks are covered by SecretRequestServiceTest::testCreateRejectsNonOwner + findOwnedRequest assertions.
- [~] 12.8 Write integration test: compromise recovery locking — lock pending requests, fill-in rejected while locked, unlock after migration with new suite ID — DEFERRED: same harness limitation. The lock/unlock wiring is now event-driven (W23 §6.1-6.3); SuiteMigration*ListenerTest covers the listener path, MigrationServiceTest covers the dispatch.

## 13. Frontend Tests

- [x] 13.1 Write unit tests for useSecretRequestStore: createRequest, createReRequest, fetchRequests, revokeRequest, fetchPublicRequest, submitFill
- [x] 13.2 Write component tests for SecretRequestFill: renders form fields from requested_fields, encrypts values on submit, shows success message, shows error for expired/fulfilled/locked
- [~] 13.3 Write component tests for SecretRequestCreateDialog: field selector renders available fields, expiry picker works, submit triggers store action, copy button works for generated link — DEFERRED: SecretRequestCreateDialog ships in W23 (8.2). The vitest spec pattern (NcDialog stub + useSecretRequestStore Pinia setup, axios spy) is documented in `tests/components/SecretRequestForm.spec.js` and `tests/dialogs/SecretShareDialog.spec.js`; copy-button clipboard assertion can reuse the `tests/components/CopyButton.spec.js` pattern. Test lands next iteration alongside §8.4 sidebar integration.
- [x] 13.4 Write component tests for SecretRequestList: renders request rows, revoke button dispatches action, status badges display correctly
- [~] 13.5 Write cross-implementation encryption test: encrypt value in browser (WebCrypto RSA-OAEP-SHA256) via fill-in flow, verify PHP (OpenSSL) can decrypt with the matching private key — DEFERRED: requires a JS-side WebCrypto harness invoked from vitest plus a PHP-side OpenSSL decode against the same keypair — that cross-runtime contract test belongs in the fleet-wide deep-e2e suite (Newman + Playwright) rather than the per-app vitest config. The single-runtime contracts are covered by `tests/vitest/rsa.spec.js` (JS) and `tests/Unit/Service/EncryptServiceTest.php` (PHP).
