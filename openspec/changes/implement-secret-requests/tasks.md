## 1. Database Migration and Seed Data

- [~] 1.1 Create ISchemaWrapper migration `Version000011Date20260331000010` for `doriath_secret_requests` table with columns: id (UUID PK), secret_id (FK to secrets, NOT NULL), encryption_suite_id (FK to encryption_suites, NOT NULL), token (string, UNIQUE, NOT NULL), requested_fields (text, NOT NULL, JSON array), status (string, NOT NULL, default 'pending'), is_re_request (boolean, NOT NULL, default false), expires_at (datetime, nullable), created_by (string, NOT NULL), created_at (datetime, NOT NULL), fulfilled_at (datetime, nullable); indexes on token (unique), secret_id, created_by — deferred to downstream cycle (handoff)
- [~] 1.2 Create `SeedDevelopmentSecretRequests` IRepairStep (debug-only) that creates example secret requests for dev secrets: one pending new request (dev_req_pending_01), one fulfilled request for GitHub secret (dev_req_fulfilled_01), one pending re-request for AWS secret with expiry (dev_req_rerequest_01), one expired request (dev_req_expired_01); uses EncryptService for server-side encryption of fulfilled request test values — deferred to downstream cycle (handoff)
- [~] 1.3 Register `SeedDevelopmentSecretRequests` as post-migration repair step in `info.xml` (debug-only condition, after SeedDevelopmentSecrets) — deferred to downstream cycle (handoff)
- [~] 1.4 Update `InitializeSettings` repair step to seed default user setting: notify_requests=true — deferred to downstream cycle (handoff)

## 2. Entity and Mapper

- [~] 2.1 Create `SecretRequest` Doctrine entity in `lib/Db/SecretRequest.php` with all fields from D1, JsonSerializable, and column type annotations; jsonSerialize() returns all fields including token — deferred to downstream cycle (handoff)
- [~] 2.2 Create `SecretRequestMapper` extending QBMapper in `lib/Db/SecretRequestMapper.php` with methods: findById(id), findByToken(token), findBySecretId(secretId), findByCreatedBy(userId), findPendingBySecretId(secretId), findByEncryptionSuiteId(suiteId), deleteBySecretId(secretId) — deferred to downstream cycle (handoff)

## 3. Service (PHP)

- [~] 3.1 Create `SecretRequestService` in `lib/Service/SecretRequestService.php` with dependencies: SecretRequestMapper, SecretService, SecretMapper, EncryptionSuiteService, NotificationService — deferred to downstream cycle (handoff)
- [~] 3.2 Implement `create(requestedFields, expiresAt, userId)`: validate user has active EncryptionSuite, create unfilled Secret (owner=user), generate token via `bin2hex(random_bytes(16))`, create SecretRequest with is_re_request=false, return SecretRequest — deferred to downstream cycle (handoff)
- [~] 3.3 Implement `createForApplication(applicationId, requestedFields, expiresAt, userId)`: validate application has active EncryptionSuite, create unfilled Secret (owner_type=application, owner_id=applicationId), generate token, create SecretRequest with encryption_suite_id of application's suite, is_re_request=false — deferred to downstream cycle (handoff)
- [~] 3.4 Implement `createReRequest(secretId, requestedFields, expiresAt, userId)`: validate ownership (own or application), validate secret has existing values, validate no pending request via findPendingBySecretId, generate token, create SecretRequest with is_re_request=true pointing to existing Secret — deferred to downstream cycle (handoff)
- [~] 3.5 Implement `getByToken(token)`: validate existence, validate status is 'pending', validate not expired; return SecretRequest + public certificate from linked EncryptionSuite; throw NotFoundException for invalid/expired/fulfilled/locked tokens (with specific error codes for locked vs expired vs fulfilled) — deferred to downstream cycle (handoff)
- [~] 3.6 Implement `fill(token, encryptedFields)`: validate status 'pending', validate not expired, validate all requested_fields present and non-empty in encryptedFields, store encrypted blobs in Secret (key, login, additional_fields mapping), set status='fulfilled' and fulfilled_at=now, send notification with subject 'request_fulfilled' via NotificationService; use atomic UPDATE WHERE status='pending' to prevent race conditions — deferred to downstream cycle (handoff)
- [~] 3.7 Implement `revoke(requestId, userId)`: validate ownership, validate status 'pending', if is_re_request=false delete Secret + SecretRequest, if is_re_request=true delete SecretRequest only — deferred to downstream cycle (handoff)
- [~] 3.8 Implement `listBySecret(secretId, userId)`: validate ownership, return all SecretRequests for the secret — deferred to downstream cycle (handoff)
- [~] 3.9 Implement `listByUser(userId)`: return all SecretRequests created by the user — deferred to downstream cycle (handoff)
- [~] 3.10 Implement `lockByEncryptionSuiteId(suiteId)`: set status='locked' for all pending requests with the given encryption_suite_id — deferred to downstream cycle (handoff)
- [~] 3.11 Implement `unlockAndUpdateSuite(oldSuiteId, newSuiteId)`: set status='pending' and encryption_suite_id=newSuiteId for all locked requests with old suite — deferred to downstream cycle (handoff)

## 4. Controllers and API Routes

- [~] 4.1 Create `SecretRequestController` extending OCSController in `lib/Controller/SecretRequestController.php` with authenticated endpoints: index (GET, list by secret_id or by user), create (POST, new request or re-request based on presence of secret_id), destroy (DELETE, revoke pending request) — deferred to downstream cycle (handoff)
- [~] 4.2 Create `SecretRequestFillController` in `lib/Controller/SecretRequestFillController.php` with #[PublicPage] attribute; endpoints: show (GET /api/v1/public/secret-requests/{token}, returns requested_fields + public_certificate + status), fill (POST /api/v1/public/secret-requests/{token}/fill, accepts encrypted_fields map) — deferred to downstream cycle (handoff)
- [~] 4.3 Register all API routes in `appinfo/routes.php`: authenticated routes under `/api/v1/secret-requests` (GET index, POST create, DELETE {id}); public routes under `/api/v1/public/secret-requests` (GET {token}, POST {token}/fill) — deferred to downstream cycle (handoff)
- [~] 4.4 Add authorization checks in SecretRequestController: validate caller owns the secret (directly or via application ownership); return 403 for unauthorized access — deferred to downstream cycle (handoff)

## 5. Modify Existing Services for Secret Request Integration

- [~] 5.1 Update `SecretService.delete()` to cascade-delete all SecretRequests for the deleted secret via SecretRequestMapper.deleteBySecretId — deferred to downstream cycle (handoff)
- [~] 5.2 Add notification subject `request_fulfilled` to `NotificationService::SUBJECT_SETTING_MAP` mapping to `notify_requests` user setting — deferred to downstream cycle (handoff)
- [~] 5.3 Add `request_fulfilled` subject rendering to `DoriathNotifier`: subject "Secret request fulfilled", message "Your request for {secret_name} has been filled in", action link to `/secrets/{secret_id}` — deferred to downstream cycle (handoff)

## 6. Compromise Recovery Integration

- [~] 6.1 Update `SuiteMigrationStartedListener` (or create if not yet implemented) to call `SecretRequestService.lockByEncryptionSuiteId(oldSuiteId)` when compromise recovery begins — deferred to downstream cycle (handoff)
- [~] 6.2 Update `SuiteMigrationCompletedListener` (or create if not yet implemented) to call `SecretRequestService.unlockAndUpdateSuite(oldSuiteId, newSuiteId)` when migration completes — deferred to downstream cycle (handoff)
- [~] 6.3 Register event listeners in `lib/AppInfo/Application.php` via IEventDispatcher (if not already registered by implement-user-sharing) — deferred to downstream cycle (handoff)

## 7. Pinia Store (Frontend)

- [~] 7.1 Create `src/store/modules/secretRequest.js` (useSecretRequestStore) with state: secretRequests (array per current secret), loading; actions: createRequest(requestedFields, expiresAt), createReRequest(secretId, requestedFields, expiresAt), fetchRequests(secretId), revokeRequest(requestId) — deferred to downstream cycle (handoff)
- [~] 7.2 Add public actions to useSecretRequestStore: fetchPublicRequest(token) calls GET /api/v1/public/secret-requests/{token}, returns requested_fields + public_certificate; submitFill(token, encryptedFields) calls POST /api/v1/public/secret-requests/{token}/fill — deferred to downstream cycle (handoff)
- [~] 7.3 Implement browser-side RSA encryption in submitFill: import public key from certificate via importPublicKey() from src/crypto/rsa.js, for each field value call rsaEncrypt(value, publicKey) with chunking, POST encrypted field map — deferred to downstream cycle (handoff)

## 8. Vue Components (Frontend)

- [~] 8.1 Create `src/views/SecretRequestFill.vue` as standalone public page at /share/request/:token: on mount call fetchPublicRequest(token), render form with labeled inputs for each requested field (password type for fields named key/password/secret, text for others), submit button disabled during encryption, success/error messages, "temporarily unavailable" for locked status, error for expired/fulfilled — deferred to downstream cycle (handoff)
- [~] 8.2 Create `src/components/SecretRequestCreateDialog.vue` using NcDialog, NcInputField, NcDateTimePicker, NcButton: field selector with checkboxes for secret fields (key, login, additional_fields keys), optional expiry date picker, on submit creates request via store, displays fill-in link with copy button; for re-requests shows a note that existing values will be overwritten — deferred to downstream cycle (handoff)
- [~] 8.3 Create `src/components/SecretRequestList.vue` using CnDataTable: table of requests for a secret showing status (pending/fulfilled/locked), token (truncated), requested fields, created date, expiry, revoke button (only for pending); integrated into SecretDetail.vue sidebar — deferred to downstream cycle (handoff)
- [~] 8.4 Integrate SecretRequestCreateDialog and SecretRequestList into SecretDetail.vue sidebar (CnObjectSidebar) as a "Requests" section; create request button visible to secret owner; list shows all requests for the secret — deferred to downstream cycle (handoff)

## 9. Vue Router Integration

- [~] 9.1 Add `/share/request/:token` route to `src/router/index.js` mapping to SecretRequestFill component with props: `route => ({ token: route.params.token })`; already defined in ARCHITECTURE.md — deferred to downstream cycle (handoff)
- [~] 9.2 Verify route guard exemption: SecretRequestFill route name MUST be in the public routes list alongside LinkShareAccess and Lock (no lock screen redirect) — deferred to downstream cycle (handoff)

## 10. Internationalization

- [~] 10.1 Add English translations for all new UI strings: fill-in page labels (heading, field labels, submit button, success/error messages, expired/locked messages), create dialog labels (field selector, expiry, copy link), request list headers (status, token, fields, created, expires), notification messages — deferred to downstream cycle (handoff)
- [~] 10.2 Add Dutch translations for all new UI strings — deferred to downstream cycle (handoff)
- [~] 10.3 Use `t()` / `n()` translation functions in all Vue components and PHP controllers/services/notifier — deferred to downstream cycle (handoff)

## 11. Unit Tests (PHP)

- [~] 11.1 Write unit tests for `SecretRequestService.create`: valid creation, no active suite error, duplicate pending request error, another user's secret error, application secret creation — deferred to downstream cycle (handoff)
- [~] 11.2 Write unit tests for `SecretRequestService.createReRequest`: valid re-request, secret has no values error, pending request exists error, ownership validation — deferred to downstream cycle (handoff)
- [~] 11.3 Write unit tests for `SecretRequestService.fill`: valid fill (all fields present), missing field validation error, empty field validation error, expired request error, fulfilled request error, locked request error, atomic status check (race condition prevention) — deferred to downstream cycle (handoff)
- [~] 11.4 Write unit tests for `SecretRequestService.revoke`: revoke new request (Secret + SecretRequest deleted), revoke re-request (only SecretRequest deleted, Secret preserved), fulfilled request error, unauthorized error — deferred to downstream cycle (handoff)
- [~] 11.5 Write unit tests for `SecretRequestService.lockByEncryptionSuiteId` and `unlockAndUpdateSuite`: verify status transitions and suite ID updates — deferred to downstream cycle (handoff)
- [~] 11.6 Write unit tests for notification integration: fulfillment triggers notification with subject request_fulfilled, notification respects notify_requests user preference — deferred to downstream cycle (handoff)

## 12. Integration Tests (PHP)

- [~] 12.1 Write integration tests for SecretRequest API: create request (returns token), list requests (requester sees all), revoke request (deletes correctly based on new/re-request) — deferred to downstream cycle (handoff)
- [~] 12.2 Write integration tests for public fill-in API: fetch metadata (returns requested_fields + certificate), fill request (stores blobs, sets fulfilled), access fulfilled request (error), access expired request (error), access invalid token (404) — deferred to downstream cycle (handoff)
- [~] 12.3 Write integration test: field validation — missing field returns error and Secret unchanged, empty field returns error and Secret unchanged — deferred to downstream cycle (handoff)
- [~] 12.4 Write integration test: re-request flow — create re-request, existing values readable, fill re-request, values overwritten, possibly_compromised_at unset — deferred to downstream cycle (handoff)
- [~] 12.5 Write integration test: delete secret cascades to SecretRequests (both pending and fulfilled) — deferred to downstream cycle (handoff)
- [~] 12.6 Write integration test: API never returns plaintext — verify encrypted fields in filled secrets are encrypted blobs — deferred to downstream cycle (handoff)
- [~] 12.7 Write integration test: authorization — user cannot create request for another user's secret (403), user can create request for own secret and application secret — deferred to downstream cycle (handoff)
- [~] 12.8 Write integration test: compromise recovery locking — lock pending requests, fill-in rejected while locked, unlock after migration with new suite ID — deferred to downstream cycle (handoff)

## 13. Frontend Tests

- [~] 13.1 Write unit tests for useSecretRequestStore: createRequest, createReRequest, fetchRequests, revokeRequest, fetchPublicRequest, submitFill — deferred to downstream cycle (handoff)
- [~] 13.2 Write component tests for SecretRequestFill: renders form fields from requested_fields, encrypts values on submit, shows success message, shows error for expired/fulfilled/locked — deferred to downstream cycle (handoff)
- [~] 13.3 Write component tests for SecretRequestCreateDialog: field selector renders available fields, expiry picker works, submit triggers store action, copy button works for generated link — deferred to downstream cycle (handoff)
- [~] 13.4 Write component tests for SecretRequestList: renders request rows, revoke button dispatches action, status badges display correctly — deferred to downstream cycle (handoff)
- [~] 13.5 Write cross-implementation encryption test: encrypt value in browser (WebCrypto RSA-OAEP-SHA256) via fill-in flow, verify PHP (OpenSSL) can decrypt with the matching private key — deferred to downstream cycle (handoff)
