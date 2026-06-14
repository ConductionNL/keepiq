## Context

Doriath is an encrypted secrets manager for Nextcloud. The implement-encryption-suites change provides the cryptographic foundation (EncryptionSuite entity, CertificateAuthorityService, DecryptService/EncryptService, WebCrypto module, session store with lock/unlock, and lock screen with route guard). The implement-secrets change provides core data entities (Secret, SecretType, Folder) with full CRUD, search, and unified search integration. The implement-user-sharing change provides SecretShare, GroupShare, NotificationService, DoriathNotifier, and sync-on-update. The implement-secret-requests change provides SecretRequest for write-without-read fill-in links.

With these foundations in place, Doriath is a full-featured user vault. However, external and internal applications (OpenConnector, CI/CD pipelines, microservices) have no way to register as vault consumers and manage their own secrets. Application management is the bridge between Doriath as a personal vault and Doriath as a shared secret store for the broader Nextcloud ecosystem.

The existing codebase after dependency changes will have: database tables (encryption_suites, ca_certificates, suite_migrations, secrets, secret_types, folders, secret_shares, group_shares, secret_delegations, link_shares, secret_requests), controllers and services for all CRUD, sharing, and request operations, NotificationService with DoriathNotifier, WebCrypto module, session store, Vue Router with navigation guards. The implement-dashboard-settings change provides DashboardService (with pending_apps_count already wired in the summary response), admin settings UI (with an ApplicationQueueSection placeholder), and PendingAppsCard on the dashboard. No application-related entities, migrations, services, or controllers exist yet.

## Goals / Non-Goals

**Goals:**
- Implement Application entity with ISchemaWrapper database migration (Version000012)
- Implement application registration open to any user (including anonymous); admin auto-approved, non-admin enters pending queue
- Implement EncryptionSuite provisioning via two paths: CSR upload (PKCS#10, app manages own private key) or server-generated RSA-4096 key pair (private key returned once)
- Implement approval queue for vault administrators to approve or reject pending applications
- Implement application deletion with hard cascade (application + EncryptionSuite + all secrets)
- Implement write-without-read: users write secrets for applications encrypted with the app's public certificate
- Implement RFC 7523 JWT Bearer API authentication for application secret retrieval
- Implement admin notification on pending registration via NotificationService
- Wire pending applications counter into existing DashboardService
- Create development seed data for applications

**Non-Goals:**
- Application-to-application sharing (sharing between two registered apps)
- Bulk application approval/rejection (admin bulk operations)
- Application API rate limiting beyond Nextcloud's standard middleware
- Application audit trail (Enterprise tier)
- OpenConnector integration UI (OpenConnector will use the API directly)
- Application secret rotation workflow (future)
- Application groups or teams (future)

## Decisions

### D1: Database Migration -- Version000012

Create ISchemaWrapper migration `Version000012Date20260331000011` for the `doriath_applications` table. This continues the version numbering from implement-secret-requests (which ends at Version000011).

Columns:
- `id` (UUID, PK)
- `name` (string, NOT NULL) -- human-readable name
- `description` (text, nullable) -- optional description of the application's purpose
- `type` (string, NOT NULL, default `external`) -- `internal` or `external`; informational only
- `status` (string, NOT NULL, default `pending`) -- `pending`, `active`
- `registered_by` (string, nullable) -- Nextcloud user ID or null (anonymous registration)
- `approved_by` (string, nullable) -- admin user ID; null if pending
- `created_at` (datetime, NOT NULL)
- `approved_at` (datetime, nullable)

Indexes: index on `status` (for pending queue queries), index on `registered_by`.

**Why:** Own database table per ADR-001. The Application entity is lightweight -- its cryptographic identity is held in the EncryptionSuite via polymorphic ownership (ADR-002, `owner_type=application`, `owner_id=application.id`). No encrypted fields on the Application itself.

### D2: Application Entity and Mapper

The `Application` Doctrine entity in `lib/Db/Application.php` implements `\JsonSerializable`. The mapper (`ApplicationMapper`) extends `QBMapper` and provides methods: `findById(id)`, `findAll(filters, sort, limit, offset)`, `findPending()`, `countPending()`, `findByRegistrant(userId)`, `findActiveByName(name)`.

The `jsonSerialize()` method returns all fields. No encrypted fields exist on this entity -- all sensitive data (certificates, private keys) lives in the linked EncryptionSuite.

**Why:** Standard Nextcloud entity/mapper pattern (ADR-008). The entity is simple because cryptographic complexity is delegated to the EncryptionSuite via polymorphic ownership.

### D3: Application Registration Flow

```
Browser / API Client                         Server
  |                                            |
  |  POST /api/v1/applications                 |
  |  { name, type, description, csr? }  ----->|
  |                                            |  1. Create Application record
  |                                            |  2. Determine status:
  |                                            |     - caller is admin → status=active
  |                                            |     - caller is non-admin/anon → status=pending
  |                                            |  3. If active + CSR provided:
  |                                            |     - Validate PKCS#10 CSR format
  |                                            |     - Extract public key from CSR
  |                                            |     - Sign with CA intermediate
  |                                            |     - Create EncryptionSuite (owner_type=application,
  |                                            |       private_key=null — held externally)
  |                                            |  4. If active + no CSR:
  |                                            |     - Generate RSA-4096 key pair
  |                                            |     - Sign certificate with CA intermediate
  |                                            |     - Create EncryptionSuite (owner_type=application,
  |                                            |       private_key=null — NOT stored)
  |                                            |     - Return private key PEM in response (one-time)
  |                                            |  5. If pending:
  |                                            |     - Store CSR in Application entity (csr field, temporary)
  |                                            |     - No EncryptionSuite yet
  |                                            |     - Notify admins via NotificationService
  |  <---- { application, private_key? }       |
```

CSR storage for pending applications: the CSR is stored temporarily in a `csr` text column on the Application entity (nullable, cleared after approval). This avoids a separate storage mechanism and keeps the approval flow simple.

**Why:** The CSR must survive between registration and approval. Storing it on the entity is the simplest approach. It is cleared after the EncryptionSuite is created during approval.

### D4: CSR Processing via OpenSSL

CSR validation and processing uses PHP's OpenSSL functions:

1. `openssl_csr_get_public_key($csrPem)` -- extract public key
2. `openssl_csr_get_subject($csrPem)` -- validate CSR structure
3. Validate key size >= 4096 bits via `openssl_pkey_get_details($pubKey)['bits']`
4. Sign with CA intermediate via `CertificateAuthorityService::signPublicKey($publicKeyPem)`

The CSR subject is not used for certificate generation -- Doriath generates its own certificate with `CN=app:{application_id}`. The CSR is used solely as a transport mechanism for the public key.

**Why:** PKCS#10 (RFC 2986) is the standard mechanism for submitting a public key for signing. Using the CSR's subject would create naming conflicts. Doriath controls the certificate subject to ensure uniqueness.

### D5: Generated Key Pair Path

When no CSR is provided, the server generates an RSA-4096 key pair:

1. `openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA])`
2. Extract public key PEM
3. Sign with CA intermediate via `CertificateAuthorityService::signPublicKey($publicKeyPem)`
4. Create EncryptionSuite with `private_key = null` (application manages externally)
5. Return private key PEM in the registration response -- **one-time only**

The private key is never stored in the database. It is returned in the API response body and the caller is responsible for secure storage. If lost, the application must be re-registered (new key pair, new EncryptionSuite; existing secrets become inaccessible).

**Why:** This path provides a zero-setup experience for applications that do not have pre-existing key infrastructure. The one-time return is the only secure approach without storing the private key (which would violate the principle that private keys are never stored unencrypted).

### D6: Approval Flow

```
Admin Browser                                Server
  |                                            |
  |  POST /api/v1/applications/{id}/approve -->|
  |                                            |  1. Validate status = pending
  |                                            |  2. Set status = active, approved_by, approved_at
  |                                            |  3. If CSR stored:
  |                                            |     - Process CSR (same as D3 step 3)
  |                                            |     - Create EncryptionSuite
  |                                            |     - Clear CSR from Application
  |                                            |  4. If no CSR:
  |                                            |     - Generate key pair (same as D3 step 4)
  |                                            |     - Return private key in response
  |  <---- { application, private_key? }       |
  |                                            |
  |  POST /api/v1/applications/{id}/reject --->|
  |                                            |  1. Validate status = pending
  |                                            |  2. Hard delete Application record
  |  <---- { success }                         |
```

Rejection is a hard delete -- the pending Application record is removed entirely. There is no rejected state. This keeps the data model simple and avoids accumulating dead records.

**Why:** Rejected applications have no value to retain. A hard delete is simpler than a soft-delete with a `rejected` status that adds query complexity.

### D7: Hard Cascade on Application Deletion

Application deletion performs a hard cascade in this order:

1. Delete all Secrets where `owner_type=application` AND `owner_id=application.id`
2. Delete all SecretRequests associated with those Secrets
3. Delete the EncryptionSuite where `owner_type=application` AND `owner_id=application.id`
4. Delete the Application record

The cascade is implemented in `ApplicationService.delete()` using a database transaction. All four steps must succeed or none.

**Why:** An application's secrets are useless without the application's private key (which the application holds externally). Deleting the EncryptionSuite invalidates the public certificate. Keeping orphaned secrets or suites adds complexity with no value.

### D8: Write-Without-Read for Application Secrets

Users can write secrets attributed to an application. The flow:

1. User's browser fetches the application's public certificate via `GET /api/v1/encryption-suites?owner_type=application&owner_id={appId}`
2. Browser encrypts the secret value using `rsaEncrypt()` from `src/crypto/rsa.js` with the application's public key
3. Browser sends `POST /api/v1/secrets` with `owner_type=application`, `owner_id=appId`, encrypted fields
4. Server stores the encrypted blob
5. User cannot decrypt (they do not have the application's private key)

The application retrieves its secrets via the JWT-authenticated API and decrypts locally with its own private key.

**Why:** This is the asymmetric encryption property described in ADR-003. The public certificate is available to anyone; encryption requires only the public key. Only the private key holder (the application) can decrypt. This enables secure credential provisioning without the provisioning user ever seeing the plaintext.

### D9: RFC 7523 JWT Bearer Authentication

Applications authenticate to Doriath's API using RFC 7523 (JWT Bearer assertion):

```
Application                                  Server
  |                                            |
  |  1. Sign JWT with own RSA private key:     |
  |     { iss: app_id,                         |
  |       sub: app_id,                         |
  |       aud: "doriath",                       |
  |       iat: now,                             |
  |       exp: now + 60,                        |
  |       jti: unique_id }                      |
  |                                            |
  |  POST /api/v1/token                        |
  |  grant_type=urn:ietf:params:oauth:          |
  |    grant-type:jwt-bearer                    |
  |  assertion={signed_jwt}  ----------------->|
  |                                            |  2. Parse JWT header → extract kid or iss
  |                                            |  3. Look up Application by id (= iss claim)
  |                                            |  4. Get EncryptionSuite's public certificate
  |                                            |  5. Verify JWT signature with public key
  |                                            |  6. Validate claims: aud, exp, iat, jti
  |                                            |  7. Check jti not reused (in-memory cache, 5min TTL)
  |                                            |  8. Generate short-lived access token (5min)
  |  <---- { access_token, expires_in: 300 }   |
  |                                            |
  |  GET /api/v1/app/secrets                   |
  |  Authorization: Bearer {access_token} ---->|
  |                                            |  9. Validate access token
  |                                            |  10. Return encrypted secrets for app
  |  <---- { secrets: [...] }                  |
```

**JWT library: `web-token/jwt-framework`** -- this is the standard JWT library across all Conduction apps. NOT firebase/php-jwt.

The access token is a server-generated opaque token stored in memory (APCu or `OCP\ICache`) with a 5-minute TTL. It is NOT a JWT itself -- keeping it opaque simplifies revocation and avoids key management for server-signed tokens.

**Why:** RFC 7523 is a standard OAuth2 extension grant that leverages the existing RSA key pair from registration. No new credentials are introduced. The short-lived access token limits the blast radius of token theft. The `jti` replay cache prevents assertion reuse.

### D10: JwtAuthService

A new `JwtAuthService` in `lib/Service/JwtAuthService.php` handles:

1. **Token exchange** (`exchangeAssertion(string $assertion): array`):
   - Deserialize JWT using `web-token/jwt-framework` JWSLoader
   - Extract `iss` claim to look up Application
   - Verify signature against application's public certificate using JWSVerifier with RS256
   - Validate claims: `aud === 'doriath'`, `exp > now`, `iat <= now`, `jti` not replayed
   - Generate opaque access token via `bin2hex(random_bytes(32))`
   - Store token in cache with application ID and 5-minute TTL
   - Return `{ access_token, token_type: 'Bearer', expires_in: 300 }`

2. **Token validation** (`validateAccessToken(string $token): ?Application`):
   - Look up token in cache
   - Return the associated Application entity or null

**Dependencies:** `web-token/jwt-framework` (composer package), `ApplicationMapper`, `EncryptionSuiteMapper`, `OCP\ICacheFactory`.

### D11: JwtAuthMiddleware

A Nextcloud `OCP\AppFramework\Middleware` that authenticates application API requests:

```php
class JwtAuthMiddleware extends Middleware {
    public function beforeController($controller, $methodName): void {
        if (!$controller instanceof ApplicationApiController) {
            return; // only apply to app API controllers
        }
        $header = $this->request->getHeader('Authorization');
        // Extract Bearer token
        $application = $this->jwtAuthService->validateAccessToken($token);
        if ($application === null) {
            throw new NotAuthenticatedException();
        }
        // Store application in request for controller access
    }
}
```

The middleware is registered in `lib/AppInfo/Application.php` via `IMiddlewareRegistry` (or `registerMiddleware` in the container). It only activates for controllers implementing the `ApplicationApiController` interface or extending a base class.

**Why:** Middleware cleanly separates authentication from business logic. The controller receives a validated Application entity without knowing about JWT mechanics.

### D12: Application API Endpoints

Two sets of endpoints:

**Authenticated (Nextcloud session):**

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| GET | `/api/v1/applications` | List applications (admin: all; user: own) | NC session |
| POST | `/api/v1/applications` | Register application | NC session or anonymous |
| GET | `/api/v1/applications/{id}` | Get application details | NC session (owner or admin) |
| DELETE | `/api/v1/applications/{id}` | Delete application (hard cascade) | Admin only |
| POST | `/api/v1/applications/{id}/approve` | Approve pending application | Admin only |
| POST | `/api/v1/applications/{id}/reject` | Reject pending application | Admin only |
| GET | `/api/v1/applications/pending` | List pending applications | Admin only |

**Application-authenticated (JWT Bearer):**

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| POST | `/api/v1/token` | Exchange JWT assertion for access token | JWT assertion |
| GET | `/api/v1/app/secrets` | List application's secrets | Bearer token |
| GET | `/api/v1/app/secrets/{id}` | Get specific secret (encrypted) | Bearer token |

The `/api/v1/token` endpoint is public (no NC session required). The `/api/v1/app/*` endpoints require a valid Bearer access token verified by JwtAuthMiddleware.

### D13: Admin Notification on Pending Registration

When a non-admin registers an application and the status is set to `pending`, a notification is dispatched to all vault administrators:

```php
// In ApplicationService::register()
if ($application->getStatus() === 'pending') {
    $admins = $this->groupManager->get('admin')->getUsers();
    foreach ($admins as $admin) {
        $this->notificationService->notify(
            'app_pending',
            $admin->getUID(),
            [
                'app_name' => $application->getName(),
                'app_id' => $application->getId(),
                'registered_by' => $application->getRegisteredBy() ?? t('doriath', 'Anonymous'),
            ]
        );
    }
}
```

The `app_pending` subject is added to `NotificationService::SUBJECT_SETTING_MAP` with no setting key (always sent -- admin notifications for pending approvals should not be suppressible).

The `DoriathNotifier` renders:
- Subject: "New application pending approval"
- Message: "Application '{app_name}' was registered by {registered_by} and is awaiting approval."
- Action link: deep-link to admin settings application queue

### D14: Dashboard Integration

The existing `DashboardService.fetchSummary()` from implement-dashboard-settings already includes `pending_apps_count` in its response. This change implements the `ApplicationMapper::countPending()` method that DashboardService calls.

The existing `PendingAppsCard.vue` dashboard component displays the count and links to the admin settings approval queue. No changes needed to the dashboard frontend -- only the backend mapper method needs to exist.

### D15: Service Layer Architecture

```
ApplicationController (NC session auth)
  └── ApplicationService (registration, approval, deletion)
        ├── ApplicationMapper (DB)
        ├── EncryptionSuiteService (suite creation for approved apps)
        ├── CertificateAuthorityService (CSR processing, cert signing)
        ├── SecretMapper (cascade deletion)
        ├── SecretRequestMapper (cascade deletion)
        ├── NotificationService (admin notifications)
        └── IGroupManager (admin group lookup)

ApplicationTokenController (public)
  └── JwtAuthService (JWT verification, token exchange)
        ├── ApplicationMapper
        ├── EncryptionSuiteMapper
        └── ICacheFactory

ApplicationApiController (Bearer token auth via JwtAuthMiddleware)
  └── ApplicationApiService (app secret retrieval)
        ├── SecretMapper (query app's secrets)
        └── JwtAuthMiddleware (authentication)
```

### D16: Frontend Architecture

**useApplicationStore (Pinia):**
- State: applications (array), currentApplication (object), pendingApplications (array), loading
- Actions: fetchApplications(), fetchApplication(id), registerApplication(data), deleteApplication(id), approveApplication(id), rejectApplication(id), fetchPending()
- The registerApplication action handles the two-path response: if `private_key` is present in the response, show a one-time download/copy dialog

**UI Components:**

| Component | Library Components | Purpose |
|-----------|-------------------|---------|
| `ApplicationList.vue` | CnDataTable, CnFilterBar, CnEmptyState | Main application list view |
| `ApplicationDetail.vue` | CnDetailPage, CnDetailCard, CnObjectSidebar | Application detail with secrets list |
| `ApplicationRegisterDialog.vue` | NcDialog, NcInputField, NcSelect, NcTextArea | Registration form with CSR upload option |
| `PrivateKeyDownloadDialog.vue` | NcDialog, NcButton | One-time private key display with copy/download |
| `ApplicationSecretsPanel.vue` | CnDataTable | Secrets attributed to this application (write-only for users) |
| `WriteSecretForAppDialog.vue` | NcDialog, NcInputField | Write a secret for an application (encrypts with app's cert) |

The `ApplicationQueueSection.vue` in admin settings (from implement-dashboard-settings) needs to be wired to the actual ApplicationService endpoints. It already exists as a UI placeholder.

### D17: Vue Router Integration

Two new routes added to `src/router/index.js`:

| Path | Name | Component | Props |
|------|------|-----------|-------|
| `/applications` | Applications | ApplicationList | -- |
| `/applications/:id` | ApplicationDetail | ApplicationDetail | `route => ({ applicationId: route.params.id })` |

Both routes are behind the lock screen guard (require vault session).

### D18: Anonymous Registration

Anonymous registration (no Nextcloud session) is supported via a `#[PublicPage]` attribute on the registration endpoint. The `registered_by` field is set to null. The application always starts as `pending` (anonymous cannot be admin).

The public registration endpoint accepts the same payload as the authenticated one. The response for pending applications does not include a private key (the key is only generated/returned on approval).

## Risks / Trade-offs

- **[Risk] Private key exposure during generated key pair registration** -- The private key is returned in the API response body over HTTPS. If the registrant's client does not store it securely, the key could be compromised. Mitigated by: HTTPS transport, one-time return (not stored server-side), UI warning to save the key immediately, and the ability to re-register if the key is lost.

- **[Risk] CSR with weak key** -- A CSR could contain a key smaller than 4096 bits. Mitigated by explicit key size validation in the CSR processing step. CSRs with keys < 4096 bits are rejected with a clear error message.

- **[Risk] JWT replay attacks** -- A stolen JWT assertion could be replayed within its validity window. Mitigated by the `jti` claim with server-side replay detection (in-memory cache with 5-minute TTL matching the assertion's max lifetime). After the TTL expires, the assertion is also expired by its `exp` claim.

- **[Risk] Access token theft** -- A stolen Bearer token grants 5-minute access to the application's secrets (encrypted). Mitigated by: short TTL (5 minutes), encrypted secrets (attacker still needs the private key to decrypt), and no token refresh mechanism (application must re-authenticate with a new JWT assertion).

- **[Risk] Cascade deletion data loss** -- Application deletion permanently removes all secrets. There is no undo. Mitigated by: admin-only deletion, confirmation dialog in the UI, and clear documentation that deletion is irreversible.

- **[Trade-off] No revoked/deactivated state for applications** -- Applications are either pending, active, or deleted. There is no way to temporarily disable an application without deleting it. This keeps the state machine simple. A `suspended` state could be added in V1 if needed.

- **[Trade-off] Opaque access tokens require server-side storage** -- Using opaque tokens instead of signed JWTs for access tokens requires cache storage. This is acceptable because the token count is low (one per active application session) and the TTL is short (5 minutes). APCu or OCP ICache handles this efficiently.

- **[Trade-off] web-token/jwt-framework dependency** -- Adds a composer dependency. This is mandatory per conformity requirements across all Conduction apps. The library is well-maintained and supports RS256 natively.

## Migration Plan

1. **Database migration**: Run `occ upgrade` to execute the ISchemaWrapper migration creating the `doriath_applications` table
2. **Composer dependency**: Add `web-token/jwt-framework` to `composer.json` and run `composer install`
3. **Middleware registration**: Register JwtAuthMiddleware in `lib/AppInfo/Application.php`
4. **Notification subjects**: Add `app_pending` to NotificationService and DoriathNotifier
5. **No data migration**: Greenfield -- no existing application data to migrate
6. **Rollback**: Disable the app via `occ app:disable doriath`. Tables remain but are inert. Re-enable to resume.

## Seed Data

Since Doriath uses its own database (not OpenRegister), seed data is handled through a debug-only repair step:

### SeedDevelopmentApplications (repair step -- debug mode only)

A `SeedDevelopmentApplications` repair step (registered only when `debug=true`) creates example applications. This depends on:
- `BootstrapCertificateAuthority` from implement-encryption-suites (CA must exist for signing)
- `SeedDevelopmentData` from implement-encryption-suites (creates test user EncryptionSuites)
- `SeedDevelopmentSecrets` from implement-secrets (creates example secrets)

Example applications seeded:

| Name | Type | Status | EncryptionSuite | Secrets | Notes |
|------|------|--------|-----------------|---------|-------|
| OpenConnector Dev | internal | active | Generated key pair (private key logged to debug log for dev use) | 2 secrets: "Connector API Key" (api_key type), "Connector DB Password" (database type) | Demonstrates active app with secrets |
| CI Pipeline Bot | external | pending | None (pending) | None | Demonstrates pending approval queue |
| Monitoring Agent | external | active | Generated key pair | 1 secret: "Monitoring Endpoint" (api_key type) | Demonstrates active external app |

The repair step:
1. Creates Application records with deterministic UUIDs (v5 from namespace `doriath:application:{name}`)
2. For active applications: generates RSA-4096 key pair, signs with CA intermediate, creates EncryptionSuite
3. Creates Secrets owned by active applications, encrypted with the application's public certificate using EncryptService (acceptable for seed data -- known test values)
4. Logs generated private keys to the Nextcloud debug log (for development testing only)
5. Sets `approved_by` and `approved_at` for active applications

## Open Questions

- Should the `jti` replay cache use APCu directly or go through `OCP\ICacheFactory`? Current decision: use `OCP\ICacheFactory` for portability across different Nextcloud setups (some may not have APCu). The factory provides a consistent interface.
- Should anonymous registration require a CAPTCHA? Current decision: no -- the pending queue is the mitigation. Spam registrations require admin approval and can be bulk-rejected. A CAPTCHA can be added in V1 if abuse is observed.
