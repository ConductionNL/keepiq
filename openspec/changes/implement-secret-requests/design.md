## Context

Doriath is an encrypted secrets manager for Nextcloud. The implement-encryption-suites change provides the cryptographic foundation (EncryptionSuite, CA, WebCrypto, session store, lock screen). The implement-secrets change provides the core data entities (Secret, SecretType, Folder) with full CRUD, search, and unified search integration. The implement-user-sharing change provides SecretShare, GroupShare, NotificationService, DoriathNotifier, and sync-on-update.

With these foundations in place, users can store, decrypt, and share secrets within Nextcloud. However, there is no mechanism for external parties (without Nextcloud accounts) to securely submit secret values into a user's vault. Secret requests solve this: a user creates a fill-in link, shares it with an external party, and the external party submits values that are encrypted in their browser with the requester's public certificate before being stored. The requester can only decrypt the submitted values with their master password.

The existing codebase after the dependency changes will have: database tables (encryption_suites, ca_certificates, suite_migrations, secrets, secret_types, folders, secret_shares, group_shares, secret_delegations, link_shares), controllers and services for all CRUD and sharing operations, NotificationService with DoriathNotifier, WebCrypto module with RSA encryption/decryption (`rsaEncrypt` in `src/crypto/rsa.js`), session store with lock screen, Vue Router with navigation guards (including public route exemptions for `/share/link/:token` and `/share/request/:token`).

Secret requests introduce a public-facing fill-in page that encrypts submitted values with the requester's public certificate using the same RSA-OAEP-SHA256 encryption used for user sharing. Unlike link sharing (which uses Argon2id + AES-GCM for symmetric encryption), secret requests use asymmetric encryption -- the external party encrypts with the public key, and only the private key holder can decrypt. This is the write-without-read property.

## Goals / Non-Goals

**Goals:**
- Implement SecretRequest entity with ISchemaWrapper database migration
- Implement request creation for own secrets and application secrets (not other users' secrets)
- Implement public fill-in page at `/share/request/:token` with browser-side RSA encryption using the requester's public certificate
- Implement write-once semantics: link invalidated after fulfillment
- Implement re-request for credential rotation: new request against an existing Secret, overwrite on fulfillment
- Implement field validation: all requested fields must be non-empty
- Implement optional request expiry
- Implement request revocation (delete unfilled Secret for new requests; preserve existing Secret for re-requests)
- Implement locked status during compromise recovery migration
- Implement fulfillment notification via NotificationService + DoriathNotifier
- Implement management UI for creating, viewing, and revoking requests
- Create development seed data for secret requests

**Non-Goals:**
- Link sharing (separate change -- implement-link-sharing)
- User-to-user sharing (separate change -- implement-user-sharing)
- Application secret management (separate change -- not yet created)
- Rate limiting beyond Nextcloud's standard middleware (token entropy makes guessing infeasible; write-once prevents replay)
- Server-side encryption of submitted values (always browser-side E2E)
- Per-token rate limiting or CAPTCHA on the fill-in page

## Decisions

### D1: Database Migration -- Continue Sequence

Create ISchemaWrapper migration `Version000011Date20260331000010` for the `doriath_secret_requests` table. This continues the version numbering from implement-link-sharing (which ends at Version000010).

Columns:
- `id` (UUID, PK)
- `secret_id` (string FK to secrets, NOT NULL) -- the Secret this request writes to
- `encryption_suite_id` (string FK to encryption_suites, NOT NULL) -- the suite whose public cert encrypts submitted values; updated during compromise recovery
- `token` (string, UNIQUE index, NOT NULL) -- URL-safe hex token, 128+ bits entropy
- `requested_fields` (text, NOT NULL) -- JSON array of field names (e.g. `["username","password"]`)
- `status` (string, NOT NULL, default `pending`) -- `pending`, `locked`, `fulfilled`
- `expires_at` (datetime, nullable) -- optional expiry set by requester
- `created_by` (string, NOT NULL) -- Nextcloud user ID of the requester
- `created_at` (datetime, NOT NULL)
- `fulfilled_at` (datetime, nullable)

Indexes: unique index on `token`, index on `secret_id`, index on `created_by`.

**Why:** Own database table per ADR-001. The `encryption_suite_id` is stored separately from the Secret's own `encryption_suite_id` to support the locked/unlocked lifecycle during compromise recovery -- the request's suite reference is updated to the new suite after migration completes, while the Secret's suite reference is updated during per-secret migration.

**Alternatives considered:**
- Storing status inside the Secret entity: Rejected -- a Secret can have multiple requests over its lifetime (re-requests), and each request has its own lifecycle. Coupling request state to the Secret would prevent re-request support.

### D2: SecretRequest Entity and Mapper

Create `SecretRequest` Doctrine entity in `lib/Db/SecretRequest.php` implementing `\JsonSerializable`. The `jsonSerialize()` method returns all fields. The `token` field is included in authenticated API responses (for the requester to share) but the full fill-in URL is constructed by the frontend.

Create `SecretRequestMapper` extending `QBMapper` in `lib/Db/SecretRequestMapper.php` with methods:
- `findByToken(string $token): SecretRequest` -- for public fill-in access
- `findBySecretId(string $secretId): array` -- for management list
- `findByCreatedBy(string $userId): array` -- for user's requests
- `findPendingBySecretId(string $secretId): ?SecretRequest` -- check if a pending request already exists
- `findByEncryptionSuiteId(string $suiteId): array` -- for compromise recovery lock/unlock
- `deleteBySecretId(string $secretId): void` -- cascade on secret deletion

**Why:** Standard Nextcloud entity/mapper pattern (same as all other Doriath entities). The `findPendingBySecretId` method enforces that only one pending request exists per Secret at a time (a new re-request cannot be created while one is pending).

### D3: Fill-In Encryption Flow -- Browser-Side RSA

The fill-in page encrypts submitted values with the requester's public certificate, using the same RSA-OAEP-SHA256 encryption already used for user sharing. No new crypto primitives are needed.

**Fill-in flow (public browser page, no Nextcloud auth):**

```
External Party's Browser                        Server
  |                                               |
  | 1. Navigate to /share/request/:token          |
  |                                               |
  | 2. GET /api/v1/public/secret-requests/{token} |
  |    ------------------------------------------>|
  |    <---- { requested_fields, status,           |
  |            public_certificate }                |
  |                                               |
  | 3. Display form with fields from              |
  |    requested_fields array                      |
  |                                               |
  | 4. User fills in all fields                   |
  |                                               |
  | 5. Import public key from certificate         |
  |    (WebCrypto importKey SPKI)                  |
  |                                               |
  | 6. For each field: encrypt value with          |
  |    public key (RSA-OAEP-SHA256 with chunking) |
  |                                               |
  | 7. POST /api/v1/public/secret-requests/{token}/fill
  |    { encrypted_fields: {                      |
  |        "username": "<base64 blob>",           |
  |        "password": "<base64 blob>"            |
  |    }}                                         |
  |    ------------------------------------------>|
  |                                               | 8. Validate all requested fields present
  |                                               |    and non-empty
  |                                               | 9. Store encrypted blobs in Secret
  |                                               | 10. Set status = fulfilled, fulfilled_at
  |                                               | 11. Send notification to requester
  |    <---- 200 { success: true }                |
```

The server receives pre-encrypted blobs and stores them in the linked Secret's encrypted fields. The mapping from `requested_fields` to Secret entity fields is:
- `"key"` -> Secret.key (the primary secret value)
- `"login"` -> Secret.login
- Any other field name -> stored in Secret.additional_fields JSON

**Why:** Reuses the existing `rsaEncrypt()` function from `src/crypto/rsa.js`. The public certificate is returned by the public API endpoint (it is not a secret -- that's the point of asymmetric encryption). The server validates completeness but does not verify encryption correctness (it cannot, without the private key).

**Alternatives considered:**
- Argon2id + AES (like link sharing): Rejected -- link sharing uses symmetric encryption because both the creator and viewer need to decrypt. Secret requests are write-only: the submitter encrypts but cannot decrypt. Asymmetric RSA is the correct primitive.
- Server-side encryption via EncryptService: Rejected for the browser fill-in path -- it would require the plaintext to transit to the server, violating E2E. However, EncryptService IS used for seed data (acceptable since those are known test values).

### D4: Re-Request Architecture

A re-request creates a new SecretRequest pointing to an existing, already-filled Secret. The key differences from a new request:

1. **No new Secret created** -- the existing Secret keeps its current values (readable while re-request is pending)
2. **On fulfillment, overwrite in place** -- the new encrypted blobs replace the existing Secret fields
3. **Sync-on-update triggers** -- if the Secret has active SecretShares, the server must flag that sync is needed. The sync itself is browser-side (the requester's browser re-encrypts for each recipient after decryption).
4. **`possibly_compromised_at` unset** -- if the Secret was flagged, fulfillment of a re-request clears the flag (fresh credential rotation)

The system enforces at most one pending request per Secret. Creating a re-request while one is already pending returns an error.

**Why:** Re-request is the credential rotation mechanism. The existing values remain accessible during the rotation window, ensuring no service disruption. Sync-on-update reuses the existing infrastructure from implement-user-sharing.

### D5: Public Route -- No Auth, No Lock Screen

The Vue Router route `/share/request/:token` maps to the `SecretRequestFill` component and is already defined in ARCHITECTURE.md as excluded from the lock screen navigation guard (alongside `/share/link/:token`). This route:
- Does NOT require Nextcloud authentication (the controller uses `#[PublicPage]` attribute)
- Does NOT check the session store for a CryptoKey
- Does NOT redirect to `/lock`
- Renders a standalone page with Doriath branding, a form for the requested fields, and a submit button

The PHP controller for the public fill-in endpoints (`SecretRequestFillController`) uses the `#[PublicPage]` attribute to bypass Nextcloud authentication checks.

**Why:** Fill-in links are for external parties without Nextcloud accounts. The page must be fully public. This follows the same pattern as `LinkShareAccessController` from implement-link-sharing.

### D6: Service Layer Architecture

```
SecretRequestController (authenticated -- CRUD for requester)
  └── SecretRequestService (business logic)
        ├── SecretRequestMapper (DB access)
        ├── SecretService (create unfilled Secret, ownership validation)
        ├── SecretMapper (store encrypted fields on fulfillment)
        ├── EncryptionSuiteService (fetch public cert, validate active suite)
        └── NotificationService (fulfillment notification)

SecretRequestFillController (public -- fetch metadata + fill-in endpoint)
  └── SecretRequestService
        ├── SecretRequestMapper
        ├── SecretMapper
        └── NotificationService
```

**SecretRequestService methods:**
- `create(secretId, requestedFields, expiresAt, userId): SecretRequest` -- validates ownership (own or application secret), validates active EncryptionSuite, validates no pending request exists for the secret, creates unfilled Secret (for new requests only), generates token via `bin2hex(random_bytes(16))`, creates SecretRequest row
- `createReRequest(secretId, requestedFields, expiresAt, userId): SecretRequest` -- validates same ownership rules, validates secret has existing values (not unfilled), validates no pending request, generates new token, creates SecretRequest pointing to existing Secret
- `getByToken(token): array` -- validates existence, status is `pending`, not expired; returns SecretRequest + public certificate from the linked EncryptionSuite
- `fill(token, encryptedFields): void` -- validates status is `pending`, validates not expired, validates all requested fields present and non-empty in encryptedFields, stores blobs in Secret, sets status to `fulfilled` and `fulfilled_at`, sends notification
- `revoke(requestId, userId): void` -- validates ownership, validates status is `pending`; for new requests: deletes the unfilled Secret and the SecretRequest; for re-requests: deletes only the SecretRequest (preserves existing Secret)
- `listBySecret(secretId, userId): array` -- returns all requests for a secret (requester only)
- `listByUser(userId): array` -- returns all requests created by the user
- `lockByEncryptionSuiteId(suiteId): void` -- sets status to `locked` for all pending requests with the given suite
- `unlockAndUpdateSuite(oldSuiteId, newSuiteId): void` -- sets status back to `pending` and updates `encryption_suite_id` for all locked requests

**Why:** Two controllers separate authentication concerns (same pattern as link sharing). The service centralizes all business logic including the new/re-request distinction, validation, and notification.

### D7: Token Generation

Tokens are generated server-side using `bin2hex(random_bytes(16))`, producing a 32-character hexadecimal string with 128 bits of entropy. The token is the only identifier in the public URL: `https://cloud.example.com/apps/doriath/#/share/request/{token}`.

**Why:** Same approach as link sharing. Server-side `random_bytes()` uses the OS CSPRNG. 128 bits makes brute-force guessing infeasible.

### D8: Determining New vs Re-Request

The service distinguishes new requests from re-requests based on whether the target Secret already has encrypted values:
- **New request**: Secret has no `key` value (unfilled) -- the request creates a new Secret
- **Re-request**: Secret already has a `key` value -- the request targets an existing Secret

The API uses a single `POST /api/v1/secret-requests` endpoint. When `secret_id` is provided, it is a re-request against that existing Secret. When `secret_id` is omitted, it is a new request and the service creates an unfilled Secret.

A boolean `is_re_request` is stored on the SecretRequest entity (derived at creation time) to simplify the revoke logic (whether to delete the linked Secret).

**Why:** A single endpoint with optional `secret_id` is simpler than two separate endpoints. The `is_re_request` flag avoids re-deriving the distinction at revocation time (the Secret may have been filled by then).

### D9: API Endpoints

**Authenticated endpoints (require Nextcloud session):**

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/secret-requests` | List requests (filter by secret_id or all for user) |
| POST | `/api/v1/secret-requests` | Create a request (new or re-request) |
| DELETE | `/api/v1/secret-requests/{id}` | Revoke a pending request |

**Public endpoints (no auth required):**

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/public/secret-requests/{token}` | Fetch request metadata + public certificate |
| POST | `/api/v1/public/secret-requests/{token}/fill` | Submit encrypted values |

Query parameters for `GET /api/v1/secret-requests`:
- `secret_id` (optional) -- filter by specific secret

The authenticated endpoints validate that the current user owns the secret (directly or via application ownership). The public endpoints validate only the token, status, and expiry.

### D10: Frontend Architecture -- Pinia Store + Components

**useSecretRequestStore (Pinia):**
- State: secretRequests (array per current secret), loading
- Actions:
  - `createRequest(secretId, requestedFields, expiresAt)`: POSTs to API, returns token/link
  - `createReRequest(secretId, requestedFields, expiresAt)`: POSTs to API with secret_id, returns token/link
  - `fetchRequests(secretId)`: loads management list from API
  - `revokeRequest(requestId)`: DELETEs request
  - `fetchPublicRequest(token)`: fetches metadata + public cert from public API (used by fill-in page)
  - `submitFill(token, encryptedFields)`: POSTs encrypted values to public API

**Fill-in page encryption (SecretRequestFill.vue):**
1. On mount: call `fetchPublicRequest(token)` to get requested_fields and public_certificate
2. Render a form with one input per requested field
3. On submit: import the public key from the certificate using `importPublicKey()` from `src/crypto/rsa.js`
4. For each field value: encrypt with `rsaEncrypt(value, publicKey)` (with chunking for values > ~446 bytes)
5. Call `submitFill(token, encryptedFields)` with the map of field name -> base64 encrypted blob
6. Show success message on 200, error message on failure

**UI Components:**

| Component | Library Components Used | Purpose |
|-----------|------------------------|---------|
| `SecretRequestFill.vue` | (standalone public page) | Public fill-in page: form with requested fields, RSA encryption, submit |
| `SecretRequestCreateDialog.vue` | NcDialog, NcInputField, NcDateTimePicker, NcButton | Dialog for creating a request: field selector, optional expiry, shows generated link |
| `SecretRequestList.vue` | CnDataTable | Table of requests for a secret: status, token (truncated), created date, expiry, revoke button |

**SecretRequestFill.vue** is a full-page component rendered at `/share/request/:token`. It does NOT use the Doriath app layout. It shows:
1. Doriath logo and "Secret Request" heading
2. A form with labeled inputs for each requested field (password inputs for fields named "password", "key", "secret"; text inputs for others)
3. Submit button (disabled while encryption is running)
4. On success: "Values submitted successfully" message
5. On error (expired, fulfilled, locked, validation): appropriate error message
6. For locked requests: "This request is temporarily unavailable" message

**SecretRequestCreateDialog.vue** is opened from the SecretDetail view. It:
1. Shows a field selector (checkboxes for available secret fields: key, login, plus any additional_fields keys)
2. Shows an optional expiry date picker
3. On submit: creates the request via API, displays the fill-in link with a copy button
4. For re-requests: shows a note that existing values will be overwritten on fulfillment

These components are integrated into the SecretDetail.vue sidebar (CnObjectSidebar) as part of a "Requests" section, below the sharing tab.

### D11: Notification Integration

Fulfillment notifications use the existing NotificationService and DoriathNotifier from implement-user-sharing.

Add a new subject to `NotificationService::SUBJECT_SETTING_MAP`:
```php
'request_fulfilled' => 'notify_requests',
```

Add `notify_requests` as a new user setting (default: true) in `InitializeSettings`.

DoriathNotifier renders the `request_fulfilled` subject as:
- Subject: "Secret request fulfilled"
- Message: "Your request for {secret_name} has been filled in"
- Action link: deep-link to the secret detail page (`/secrets/{secret_id}`)

**Why:** Reuses the existing notification infrastructure. A dedicated `notify_requests` setting allows users to toggle request notifications independently from share notifications.

### D12: Compromise Recovery Integration

When a user initiates compromise recovery (EncryptionSuite migration), all pending SecretRequests for the old suite must be locked:

1. `SuiteMigrationStartedListener` calls `SecretRequestService.lockByEncryptionSuiteId(oldSuiteId)`
2. All pending requests with that suite get `status = locked`
3. The public fill-in page returns "temporarily unavailable" for locked requests
4. After migration completes, `SuiteMigrationCompletedListener` calls `SecretRequestService.unlockAndUpdateSuite(oldSuiteId, newSuiteId)`
5. All locked requests get `status = pending` and `encryption_suite_id = newSuiteId`

This ensures that during migration, no external party can submit values encrypted with the old (compromised) public certificate. After migration, the fill-in page will encrypt with the new certificate.

**Why:** If a fill-in were allowed during migration, the submitted values would be encrypted with the old key. The old key is being rotated precisely because it may be compromised. Locking prevents this window of vulnerability.

### D13: Revocation Logic -- New vs Re-Request

Revocation behavior depends on `is_re_request`:

**New request (is_re_request = false):**
- Delete the SecretRequest record
- Delete the linked unfilled Secret (it has no useful data)

**Re-request (is_re_request = true):**
- Delete the SecretRequest record only
- Preserve the existing Secret and its current encrypted values

In both cases, the token is invalidated (the record is deleted, so the public endpoint returns 404).

**Why:** For new requests, the unfilled Secret is a placeholder with no data. Leaving it around would create orphaned empty secrets. For re-requests, the existing Secret has valid, useful data that should not be affected by canceling a rotation.

## Seed Data

Since Doriath uses its own database (not OpenRegister), seed data is handled through repair steps:

### SeedDevelopmentSecretRequests (repair step -- debug mode only)

The `SeedDevelopmentSecretRequests` repair step (registered only when `debug=true`) creates example secret requests for development secrets. It depends on `SeedDevelopmentSecrets` from implement-secrets (which creates example secrets) and `SeedDevelopmentData` from implement-encryption-suites (which creates dev user encryption suites).

Example secret requests seeded:

| Secret | Token (fixed for dev) | Status | Requested Fields | Expires | Is Re-Request |
|--------|----------------------|--------|------------------|---------|---------------|
| (new unfilled) | `dev_req_pending_01` | pending | `["key","login"]` | null | false |
| GitHub (login) | `dev_req_fulfilled_01` | fulfilled | `["key","login"]` | null | false |
| AWS Console (api_key) | `dev_req_rerequest_01` | pending | `["key"]` | 2026-12-31 | true |
| (new unfilled) | `dev_req_expired_01` | pending | `["key","login"]` | 2025-01-01 | false |

The repair step:
1. Looks up existing dev user EncryptionSuites and dev secrets
2. Creates an unfilled Secret for the pending new request
3. Creates an unfilled Secret for the expired request
4. For the fulfilled request: encrypts test values with the dev user's public certificate using EncryptService (server-side, acceptable for seed data)
5. Creates SecretRequest records with deterministic tokens for predictable dev URLs
6. The expired request has `expires_at` in the past, allowing testing of expiry rejection

### Default user settings

Update `InitializeSettings` repair step to seed:
- `notify_requests`: true (default)

## Risks / Trade-offs

- **[Risk] RSA chunking on the public fill-in page** -- RSA-OAEP-SHA256 with a 4096-bit key can encrypt at most ~446 bytes per chunk. Long secret values (e.g., SSH private keys submitted as a "key" field) require chunking. The fill-in page must use the same `rsaEncrypt()` function with chunking that the rest of Doriath uses. If the chunking implementation is incorrect, decryption will fail. Mitigated by reusing the proven `rsaEncrypt()` from `src/crypto/rsa.js` without modification.

- **[Risk] Public certificate exposure** -- The requester's public certificate is returned by the public API endpoint without authentication. This is by design (the certificate is public), but it means anyone can discover which EncryptionSuite owns a request. Mitigated by the fact that the public certificate is already, by definition, public. The token itself is the secret (128 bits of entropy).

- **[Risk] Race condition on concurrent fill-in** -- If two people with the same fill-in link submit simultaneously, both could pass the `status = pending` check. Mitigated by using an atomic `UPDATE ... WHERE status = 'pending'` in the fill service. If the update affects 0 rows, the second submission returns an error.

- **[Risk] Sync-on-update after re-request fulfillment** -- Re-request fulfillment overwrites the Secret's encrypted fields. If the Secret is shared via SecretShares, the requester's browser must re-encrypt for all recipients (sync-on-update). However, the fulfillment happens on the public page (no Nextcloud auth), so sync cannot happen at fill-in time. Instead, the next time the requester opens the secret, the frontend detects that `possibly_compromised_at` was unset by the fulfillment and that sync is needed, triggering the standard sync-on-update flow. This introduces a window where shared copies have stale values. Acceptable for MVP; a push notification to connected browsers could reduce this window in the future.

- **[Trade-off] Single pending request per Secret** -- The system enforces at most one pending request per Secret. If a user needs to send fill-in links to multiple external parties for the same secret, they must wait for each to be fulfilled (or revoke and re-create). This simplifies the data model and avoids conflicts (which fill-in "wins"?). For the rare case of needing parallel submissions, the user can create separate secrets.

- **[Trade-off] No confirmation step for submitters** -- The external party submits values and sees a success message. There is no preview or confirmation dialog. This is intentional: the submitter should not see the encrypted values (they are opaque blobs), and a confirmation step would require storing the plaintext temporarily. Write-once means one shot.

- **[Trade-off] Application secret requests require user intermediary** -- A user creates a request targeting an application's vault. The request's `encryption_suite_id` points to the application's suite (not the user's). The user who creates the request does not gain the ability to decrypt the submitted values -- only the application (or an admin with the application's master password) can. This is the correct security model but may confuse users who expect to see the submitted values in their own vault.

## Migration Plan

1. **Database migration**: Run `occ upgrade` to execute the ISchemaWrapper migration creating the `doriath_secret_requests` table
2. **Development seed**: If `debug=true`, the `SeedDevelopmentSecretRequests` repair step creates example secret requests
3. **Notification registration**: Add `request_fulfilled` subject to NotificationService and DoriathNotifier (no new notifier class needed -- extend existing)
4. **User settings**: Update `InitializeSettings` to seed `notify_requests=true` default
5. **No data migration**: Greenfield -- no existing request data to migrate
6. **Rollback**: Disable the app via `occ app:disable doriath`. The secret_requests table remains but is inert. Active fill-in URLs stop working immediately (controller not loaded).

## Open Questions

- Should the fill-in page show the secret name (unencrypted metadata) to the external party? Current decision: Yes -- show the secret name so the submitter knows what they are filling in. The name is not a secret (it is unencrypted metadata like "GitHub API Key"). This differs from link sharing (where the name is hidden before password entry) because the fill-in party is expected to know what they are submitting.
- Should the `requested_fields` support custom field labels (e.g., "Database Host" instead of just a field key)? Current decision: No for MVP -- the field key is used as the label. Custom labels can be added in V1 by making `requested_fields` an array of objects instead of strings.
