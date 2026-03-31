## Context

Doriath is an encrypted secrets manager for Nextcloud. Currently it has a settings page and dashboard shell but no cryptographic infrastructure. Every feature (secrets CRUD, sharing, application credentials) is blocked until the EncryptionSuite foundation exists.

The app uses its own database tables (ADR-001), polymorphic ownership for suites (ADR-002), and an always-E2E encryption model where the master password and derived AES key never leave the browser (ADR-003).

Existing codebase has: `SettingsController`, `SettingsService`, `DashboardController`, `InitializeSettings` repair step, Vue Router, Pinia stores, and a `Dashboard.vue` view. No entities, no migrations, no encryption code exists yet.

## Goals / Non-Goals

**Goals:**
- Implement the complete EncryptionSuite lifecycle (create, revoke, reinstate, compromise recovery)
- Bootstrap a private CA (root + intermediate) with auto-renewal
- Implement client-side master password session management with WebCrypto
- Provide stateless DecryptService/EncryptService for internal Nextcloud app consumption
- Ensure PHP/OpenSSL and JS/WebCrypto produce identical ciphertext formats
- Add lock screen with Vue Router navigation guard
- Enforce master password strength via zxcvbn

**Non-Goals:**
- Secrets CRUD (separate change, depends on this one)
- Sharing (user, link, request) — depends on secrets
- Application management and CSR processing — separate change
- Multiple active suites per user (future key rotation beyond compromise)
- Custom CA upload (advanced feature, future)
- Passwordless vault unlock via WebAuthn/FIDO2 (future)

## Decisions

### D1: Database Migration Strategy — ISchemaWrapper with Nextcloud Version Class

Use Nextcloud's `ISchemaWrapper` migration pattern with a version class in `lib/Migration/`. Each table gets a dedicated migration class. The CA bootstrap runs as a separate `IRepairStep` registered in `info.xml` for `post-migration`.

**Why:** Standard Nextcloud pattern (ADR-004). Repair steps run after migrations, ensuring tables exist before CA data is inserted. Separating schema creation from data seeding keeps migrations idempotent.

**Alternatives considered:**
- Combined migration + seed: Rejected — repair steps are the Nextcloud-standard way to seed data and can be re-run via `occ maintenance:repair`.

### D2: CA Bootstrap as IRepairStep

CA bootstrap (root + intermediate generation) runs as `BootstrapCertificateAuthority` registered as a `post-migration` repair step. On failure, it sets an app config flag `ca_status = degraded` and all routes check this flag. Both the root and intermediate private keys are retained in the database, encrypted via `OCP\Security\ICrypto`. The root key is needed for signing new intermediates during renewal.

**Why:** Repair steps are idempotent and can be retried via `occ maintenance:repair` or the admin retry button. Degraded-state flag allows the app to install without crashing, which is a Nextcloud app store requirement.

**Future consideration:** A future version may support exporting the root private key to an administrator for offline safekeeping (hardware token / air-gapped device), after which it is purged from the database. This "offline root key" pattern follows high-security PKI best practice.

### D3: AES Key Derivation — PBKDF2-SHA256 in WebCrypto

Derive a 256-bit AES-GCM key from the master password using `crypto.subtle.deriveKey` with PBKDF2-SHA256 (600,000 iterations). The salt is stored alongside the encrypted private key blob. The same derivation is implemented in PHP via `hash_pbkdf2()` for the DecryptService/EncryptService internal path.

**Why:** WebCrypto supports PBKDF2 natively (no library needed). 600K iterations aligns with OWASP 2023 recommendations for PBKDF2-SHA256. Argon2id would be preferable but is not available in WebCrypto — PBKDF2 is the pragmatic choice for browser/server parity.

**Alternatives considered:**
- Argon2id via WASM: Better KDF but adds a ~200KB WASM dependency, complicates builds, and breaks the dual-implementation parity goal. Reserved for link share snapshot encryption (different use case).

### D4: Private Key Encryption Format — AES-256-GCM with Envelope

The encrypted private key blob stored in the database uses a fixed envelope format:

```
[4 bytes: version][16 bytes: salt][12 bytes: IV][N bytes: ciphertext][16 bytes: GCM tag]
```

Version `0x01` = PBKDF2-SHA256 + AES-256-GCM. Both PHP (OpenSSL `openssl_encrypt` with `aes-256-gcm`) and JS (WebCrypto `AES-GCM`) produce and consume this format. The envelope is base64-encoded for database storage.

**Why:** Fixed binary format ensures PHP and JS implementations are byte-compatible. GCM provides authenticated encryption (integrity + confidentiality). Version byte allows future algorithm upgrades without breaking existing blobs.

### D5: RSA Encryption — OAEP-SHA256 with Chunking

RSA encryption uses OAEP-SHA256 padding. With RSA-4096, the maximum plaintext per chunk is 446 bytes (512 - 2*32 - 2). Data larger than 446 bytes is split into chunks, each encrypted independently. The ciphertext format is:

```
[4 bytes: chunk count][512 bytes: chunk 1][512 bytes: chunk 2]...
```

Both PHP (`openssl_public_encrypt` with `OPENSSL_PKCS1_OAEP_PADDING` and SHA-256 digest) and JS (`crypto.subtle.encrypt` with `RSA-OAEP`) implement this identically.

**Why:** OAEP-SHA256 is the modern RSA padding standard (PKCS#1 v2.2). Chunking is necessary because RSA has a fixed plaintext size limit. The chunk-count header makes parsing deterministic.

### D6: Entity Design — Doctrine ORM with Nextcloud JsonSerializable

Three entities:
- `EncryptionSuite` — `doriath_encryption_suites` table
- `CACertificate` — `doriath_ca_certificates` table
- `SuiteMigration` — `doriath_suite_migrations` table

Each entity extends a base class and implements `\JsonSerializable`. Mappers extend `QBMapper`. The `private_key` field on EncryptionSuite and CACertificate stores the AES-256-GCM envelope (base64). Certificate fields store PEM-encoded X.509 certificates.

**Why:** Standard Nextcloud entity/mapper pattern (ADR-008). JsonSerializable allows controllers to return entities directly. QBMapper provides type-safe query building.

### D7: Service Layer Architecture

```
EncryptionSuiteController
  └── EncryptionSuiteService (business logic, suite lifecycle)
        ├── EncryptionSuiteMapper (DB access)
        ├── CertificateAuthorityService (certificate signing)
        │     └── CACertificateMapper
        └── EncryptService / DecryptService (stateless crypto)

CACertificateController
  └── CertificateAuthorityService
        └── CACertificateMapper

MigrationController
  └── MigrationService (suite migration tracking)
        ├── SuiteMigrationMapper
        └── EncryptionSuiteService
```

DecryptService and EncryptService are stateless — they take plaintext/ciphertext + keys as parameters and return results. They have no entity awareness and no database access. Internal Nextcloud apps (e.g., OpenConnector) inject these services directly.

### D8: Frontend Session Architecture

```
sessionStore (Pinia)
  ├── cryptoKey: CryptoKey | null  (extractable: false)
  ├── aesKey: CryptoKey | null     (derived from master password)
  ├── timeout: number              (ms, from user settings)
  ├── lastActivity: number         (timestamp)
  ├── isLocked: computed            (true when cryptoKey is null)
  └── actions:
      ├── unlock(masterPassword)    → derives AES key, decrypts private key, imports as CryptoKey
      ├── lock()                    → nulls cryptoKey + aesKey
      └── checkTimeout()            → locks if lastActivity + timeout < now
```

A Vue Router `beforeEach` guard checks `sessionStore.isLocked`. If locked and the target route is not `/lock`, redirect to `/lock`. The lock screen is a full-page component at the `/lock` route.

A `setInterval` timer (every 10 seconds) calls `checkTimeout()`. A `visibilitychange` listener calls `checkTimeout()` when the tab becomes visible. A `beforeunload` listener calls `lock()` to clear keys on tab close (best-effort — JS memory release on tab destruction is the real guarantee).

### D9: Lock Screen Route Guard

The lock screen is implemented as a named route (`/lock`) with a `beforeEach` navigation guard:

```javascript
router.beforeEach((to, from, next) => {
  const session = useSessionStore()
  if (to.name !== 'lock' && session.isLocked) {
    next({ name: 'lock' })
  } else if (to.name === 'lock' && !session.isLocked) {
    next({ name: 'dashboard' })
  } else {
    next()
  }
})
```

**Why:** Route guard is the Vue Router-native approach. Full-page lock screen (not overlay) prevents any vault content from being visible. Bidirectional guard prevents accessing lock screen when already unlocked.

### D10: CA Certificate Renewal Strategy

- **Intermediate auto-renewal**: A Nextcloud background job (`\OCA\Doriath\BackgroundJob\RenewIntermediateCertificate`) checks daily. If the intermediate expires within 30 days, it generates a new one, re-signs all active suites, and sends an admin notification via `OCP\Notification\IManager`.
- **Root expiry notifications**: A second background job checks root expiry and sends notifications at 90/30/7 days.
- **Forced intermediate renewal**: Admin-triggered via API endpoint. Immediately revokes old intermediate, generates new one, re-signs all active suites.

Re-signing a suite means: extract the public key from the existing certificate, generate a new X.509 certificate signed by the new intermediate, update the `certificate` field. The RSA key pair and encrypted private key are untouched.

### D11: Compromise Recovery Flow

```
Browser                                Server
  │                                      │
  ├─ POST /compromise-recovery ──────────┤
  │  {old_master_pw_hash, new_master_pw} │  (hash proves knowledge, new pw for new suite)
  │                                      ├─ Generate new RSA-4096 key pair
  │                                      ├─ Sign with active intermediate
  │                                      ├─ Create new EncryptionSuite
  │                                      ├─ Create SuiteMigration (in_progress)
  │                                      ├─ Apply write lock
  │  ◄── { new_suite, old_encrypted_pk } ┤
  │                                      │
  │  Derive old AES key (old master pw)  │
  │  Decrypt old private key (WebCrypto) │
  │  Derive new AES key (new master pw)  │
  │  Encrypt new private key (WebCrypto) │
  │                                      │
  ├─ PUT /suites/{new_id}/private-key ───┤  Store new encrypted PK
  │                                      │
  │  For each unmigrated secret:         │
  │  ├─ GET /secrets/{id}/blob ──────────┤
  │  │  Decrypt with old PK (WebCrypto)  │
  │  │  Encrypt with new pub key         │
  │  ├─ PUT /secrets/{id}/migrate ───────┤  Store re-encrypted blob
  │                                      │
  ├─ POST /migrations/{id}/complete ─────┤  Finalize migration
  │                                      ├─ Revoke link shares
  │                                      ├─ Update SecretRequests
  │                                      ├─ Set old suite = compromised
  │                                      ├─ Set migration status
  │                                      ├─ Release write lock
```

The old master password is needed client-side only (to derive the old AES key and decrypt the old private key). It is never sent to the server. The server receives a hash for identity verification only.

### D12: zxcvbn Integration

Add `zxcvbn` as an npm dependency. Create a `PasswordStrengthMeter.vue` component that:
1. Debounces input (300ms)
2. Calls `zxcvbn(password)` on each debounced change
3. Displays score (0-4) as a colored bar with text feedback
4. Exposes `isValid` computed property based on admin-configured minimum score and length
5. Emits `strength-change` event for parent form to enable/disable submit

Admin settings for minimum score (3-4) and minimum length (12-20) are stored in Nextcloud app config via `SettingsService`.

## Risks / Trade-offs

- **[Risk] PBKDF2 vs Argon2id for master password KDF** → Mitigated by using 600K iterations (OWASP recommendation). Argon2id would be stronger against GPU attacks but is not available in WebCrypto. If WebCrypto adds Argon2id support in the future, the version byte in the envelope format allows migration.

- **[Risk] Browser tab crash during compromise recovery** → Mitigated by SuiteMigration tracking. The write lock persists server-side. On re-open, the user sees a "migration paused" screen and must re-enter their master password to resume. Only unmigrated secrets (still pointing to old_suite_id) are processed.

- **[Risk] CA bootstrap failure on install** → Mitigated by degraded-state flag. The app installs successfully but displays a "CA not configured" message on all routes. Admin can retry via the admin panel or `occ maintenance:repair`.

- **[Risk] Large number of suites during CA re-signing** → Re-signing is a bulk operation (SQL update of certificate field). For instances with thousands of users, this could take significant time. Mitigated by running re-signing in a background job with batch processing (100 suites per batch).

- **[Risk] WebCrypto CryptoKey with extractable:false** → The CryptoKey cannot be serialized or transmitted, which is the security goal. However, it also means the key cannot survive a page navigation within the same tab (full page reload). Mitigated by using Vue Router (SPA, no full reloads) and re-checking key presence in the navigation guard.

- **[Trade-off] Session timeout is client-side only** → The server has no session state to expire. A modified client could theoretically skip the timeout. This is acceptable because the server only returns encrypted blobs — without the CryptoKey, the encrypted data is useless. The timeout is a UX protection, not a security boundary.

## Migration Plan

1. **Database migrations**: Run `occ upgrade` to execute ISchemaWrapper migrations creating the three tables
2. **CA bootstrap**: The `BootstrapCertificateAuthority` repair step runs automatically post-migration. If it fails, the app enters degraded state — admin can retry
3. **No data migration**: This is greenfield — no existing encryption data to migrate
4. **Rollback**: Disable the app via `occ app:disable doriath`. Tables remain but are inert. Re-enable to resume.

## Seed Data

Since Doriath uses its own database (not OpenRegister), seed data is handled through:

1. **CA Bootstrap (repair step)**: The `BootstrapCertificateAuthority` repair step generates the root certificate (20-year) and intermediate certificate (3-year) on first run. This is the initial seed data — no static fixtures are needed because certificates must be generated dynamically (unique per instance).

2. **Default app config values**: The `InitializeSettings` repair step (already exists) seeds default settings:
   - `master_password_min_length`: 12
   - `master_password_min_score`: 3
   - `session_timeout_default`: 600000 (10 minutes)
   - `ca_status`: set by bootstrap step (`healthy` or `degraded`)

3. **Development seed data**: For development/testing, a `SeedDevelopmentData` repair step (registered only in debug mode) creates:
   - A test user EncryptionSuite with a known master password (`Doriath-Dev-2024!`)
   - Ensures the CA is bootstrapped
   - This is the equivalent of `_registers.json` for apps that use OpenRegister

## Open Questions

- Should the intermediate CA private key be encrypted with a server-side key (derived from Nextcloud's `secret`) or a separate admin-provided passphrase? Current decision: use Nextcloud's `secret` via `OCP\Security\ICrypto` for the intermediate CA private key, since it must be decryptable server-side for certificate signing operations.
- What batch size is optimal for CA re-signing operations? Starting with 100 suites per batch — can be tuned based on production metrics.
