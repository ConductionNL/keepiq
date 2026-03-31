## Context

Doriath is an encrypted secrets manager for Nextcloud. The implement-encryption-suites change provides the cryptographic foundation (EncryptionSuite, CA, WebCrypto, session store, lock screen) and the implement-secrets change provides the core data entities (Secret, SecretType, Folder) with full CRUD.

With secrets in place, users need a way to share them with external parties who do not have Nextcloud accounts. Link sharing creates a password-protected, limited-use URL that allows anyone with the link and password to view a secret's value in their browser.

The existing codebase after implement-secrets will have: six database tables (encryption_suites, ca_certificates, suite_migrations, secrets, secret_types, folders), controllers and services for all CRUD operations, WebCrypto module with RSA encryption/decryption, session store with lock screen, and Vue Router with navigation guards.

Link sharing introduces a fundamentally different encryption model from the rest of Doriath: instead of RSA with the owner's key pair, link share snapshots use symmetric AES-256-GCM with a key derived from a one-time password via Argon2id. This is client-side only -- the server never sees the password or the derived key.

## Goals / Non-Goals

**Goals:**
- Implement LinkShare entity with ISchemaWrapper database migration
- Implement link share creation flow entirely in the browser (decrypt secret, generate password, Argon2id KDF, AES encrypt, POST blob)
- Implement public access page at `/share/link/:token` (no Nextcloud auth required, no lock screen guard)
- Implement two-phase access protocol: server returns encrypted blob, browser decrypts, browser confirms success
- Implement brute-force protection (5 consecutive failed attempts permanently delete the link share)
- Implement usage limit enforcement (1-10, default 1, auto-delete on limit reached)
- Implement manual revocation by secret owner
- Implement link share management UI (creation dialog, active link list per secret)
- Add Argon2id WASM dependency for browser-side KDF
- Create development seed data for link shares

**Non-Goals:**
- Group sharing (separate change)
- User-to-user sharing (separate change)
- Secret requests / fill-in links (separate change)
- Link share password change (not supported -- revoke and re-create instead)
- Link share expiry extension (not supported -- create a new link)
- Server-side Argon2id (link share decryption is always client-side)
- QR code generation for link URLs (V1 tier)

## Decisions

### D1: Database Migration -- Continue Sequence from Secrets

Create ISchemaWrapper migration `Version000010Date20260331000009` for the `doriath_link_shares` table. This continues the version numbering from implement-user-sharing (which ends at Version000009).

Columns:
- `id` (UUID, PK)
- `secret_id` (string FK to secrets, NOT NULL)
- `token` (string, UNIQUE index, NOT NULL)
- `encrypted_secret_snapshot` (text, NOT NULL) -- AES-256-GCM encrypted blob
- `argon2id_salt` (string, NOT NULL) -- base64-encoded 16-byte salt used for Argon2id KDF
- `encryption_suite_id` (string FK to encryption_suites, NOT NULL) -- suite active at creation time
- `usage_limit` (integer, NOT NULL, default 1)
- `usage_count` (integer, NOT NULL, default 0)
- `failed_attempts` (integer, NOT NULL, default 0)
- `created_by` (string, NOT NULL) -- Nextcloud user ID of the secret owner
- `created_at` (datetime, NOT NULL)
- `expires_at` (datetime, nullable)

Indexes: unique index on `token`, index on `secret_id`, index on `created_by`.

**Why:** Own database table per ADR-001. The `argon2id_salt` is stored alongside the blob so the public access page can pass it to the WASM KDF without needing the password. The `failed_attempts` column is stored server-side to prevent client-side bypass of brute-force protection.

**Note on ARCHITECTURE.md divergence:** ARCHITECTURE.md shows `usage_limit` as `null = unlimited`. This design overrides that: usage_limit is always 1-10 with no null/unlimited option. The feature spec and product requirements explicitly prohibit unlimited link shares for security reasons.

**Alternatives considered:**
- Storing the salt inside the encrypted blob: Rejected -- the salt must be extractable before decryption so the browser can run Argon2id. A separate column is clearer than a custom binary prefix.

### D2: LinkShare Entity and Mapper

Create `LinkShare` Doctrine entity in `lib/Db/LinkShare.php` implementing `\JsonSerializable`. The `jsonSerialize()` method MUST omit `encrypted_secret_snapshot` and `argon2id_salt` from the response for authenticated API calls (owner's management list). These fields are only returned by the public access endpoint.

Create `LinkShareMapper` extending `QBMapper` in `lib/Db/LinkShareMapper.php` with methods:
- `findByToken(string $token): LinkShare` -- for public access
- `findBySecretId(string $secretId): array` -- for management list
- `findByCreatedBy(string $userId): array` -- for user's link shares
- `deleteBySecretId(string $secretId): void` -- for cascade on secret deletion
- `deleteByUserId(string $userId): void` -- for cascade on compromise recovery

**Why:** Standard Nextcloud entity/mapper pattern (same as implement-encryption-suites D6 and implement-secrets D2). Omitting the blob from the management API prevents unnecessary data transfer and exposure.

### D3: Two-Phase Access Protocol

Link share access uses a two-phase protocol to correctly track usage and brute-force attempts:

**Phase 1: Fetch blob**
```
GET /api/v1/public/link-shares/{token}
→ 200 { encrypted_secret_snapshot, argon2id_salt, usage_limit, usage_count }
  (if token valid, usage_count < usage_limit, failed_attempts < 5)
→ 404 { error: "Link not found or expired" }
  (if token invalid, deleted, expired, or usage exceeded)
```

The server checks: token exists, `usage_count < usage_limit`, `failed_attempts < 5`, `expires_at` is null or in the future. If any check fails, return 404 (intentionally vague to prevent enumeration).

**Phase 2: Confirm decryption**
```
POST /api/v1/public/link-shares/{token}/confirm
→ 200 { usage_count, usage_limit, remaining }
  (increments usage_count, resets failed_attempts to 0, deletes if limit reached)
→ 404 { error: "Link not found or expired" }
```

**Failed attempt tracking:** The server sets a `last_blob_fetched_at` timestamp (in-memory or column) on Phase 1. If Phase 1 is called again without a successful Phase 2 confirm for the same token, the server increments `failed_attempts`. This is simpler than a timeout-based approach and handles the common case: a wrong password means the user will try again (Phase 1 again) without confirming.

Implementation detail: The `failed_attempts` increment happens at the START of Phase 1 for all requests after the first. The first Phase 1 for a given token does not increment (it's the initial attempt). On successful Phase 2 confirm, `failed_attempts` resets to 0. This means:
- First attempt: fetch blob (failed_attempts stays 0) -> if wrong password, no confirm
- Second attempt: fetch blob (failed_attempts increments to 1) -> if wrong password, no confirm
- ...
- Sixth attempt: fetch blob (failed_attempts would become 5) -> link share is deleted before returning blob

**Why:** The two-phase protocol ensures the server only counts a successful decryption (Phase 2 confirm) as usage. The browser handles decryption -- the server has no way to verify the password, so it relies on the client's confirmation. A malicious client could fetch the blob without confirming, but this does not help them -- without the password, the blob is useless, and failed_attempts still accumulates.

**Alternatives considered:**
- Single endpoint that returns the blob and increments usage atomically: Rejected -- the server cannot verify if decryption succeeded. A wrong password would consume a usage count, punishing honest users.
- Server-side Argon2id + decryption: Rejected -- violates ADR-003 (always E2E). The server would see plaintext, and the password would need to be transmitted.

### D4: Encryption Flow -- Client-Side Argon2id + AES-256-GCM

**Creation (in the browser):**
1. Owner's browser decrypts the secret using their CryptoKey (same as normal secret viewing)
2. Browser serializes the plaintext fields (`key`, `login`, `additional_fields`) as a JSON object
3. Browser generates a random password (20 characters, alphanumeric + symbols, using `crypto.getRandomValues()`)
4. Browser generates a random 16-byte salt using `crypto.getRandomValues()`
5. Browser derives a 256-bit AES key from the password + salt via Argon2id (WASM): memory 64 MiB, iterations 3, parallelism 1, output 32 bytes
6. Browser generates a random 12-byte IV using `crypto.getRandomValues()`
7. Browser encrypts the JSON snapshot using AES-256-GCM with the derived key and IV
8. Browser encodes the blob as: `[12 bytes IV][N bytes ciphertext + GCM tag]` (base64 for transmission)
9. Browser POSTs to `/api/v1/secrets/{secretId}/link-shares`:
   ```json
   {
     "encrypted_secret_snapshot": "<base64 blob>",
     "argon2id_salt": "<base64 salt>",
     "usage_limit": 3,
     "expires_at": "2026-04-15T00:00:00Z"
   }
   ```
10. Server generates token via `bin2hex(random_bytes(16))` (32-char hex string, 128 bits entropy), stores the row, returns:
    ```json
    {
      "id": "<uuid>",
      "token": "<hex token>",
      "link_url": "https://cloud.example.com/apps/doriath/#/share/link/<token>",
      "usage_limit": 3,
      "usage_count": 0,
      "created_at": "..."
    }
    ```
11. Browser shows the link URL and generated password to the user (copy buttons for both)

**Access (public page, no Nextcloud auth):**
1. Visitor navigates to `/share/link/:token` -- Vue Router renders the LinkShareAccess component
2. Visitor enters password
3. Browser calls Phase 1: `GET /api/v1/public/link-shares/{token}` -- receives blob + salt
4. Browser derives AES key from password + salt via Argon2id (WASM, same parameters)
5. Browser decrypts blob using AES-256-GCM
6. If decryption succeeds (GCM tag valid): display plaintext, call Phase 2 confirm endpoint
7. If decryption fails (GCM tag mismatch): display error "Incorrect password", user can retry

**Why:** Argon2id is memory-hard (64 MiB per attempt), making GPU-based brute force attacks against a captured blob extremely expensive. Combined with the 5-attempt server-side limit, this provides defense in depth. The parameters (64 MiB, 3 iterations, parallelism 1) balance security with browser performance -- Argon2id in WASM takes approximately 0.5-1 second per derivation on modern hardware, which is acceptable for a one-time password entry.

**Note:** The AES-256-GCM blob format for link shares is DIFFERENT from the private key envelope format (implement-encryption-suites D4). The private key format includes a version byte and a separate salt. The link share format is simpler: IV + ciphertext + tag. This is because the salt is stored in a separate database column, not embedded in the blob. The two formats must not be confused.

### D5: Public Route -- No Auth, No Lock Screen

The Vue Router route `/share/link/:token` maps to the `LinkShareAccess` component and is explicitly excluded from the lock screen navigation guard. This route:
- Does NOT require Nextcloud authentication (the controller uses `PublicPage` annotation)
- Does NOT check the session store for a CryptoKey
- Does NOT redirect to `/lock`
- Renders a standalone page with Doriath branding, a password input, and a submit button

The PHP controller for the public endpoint (`LinkShareAccessController`) extends `PublicShareController` (or uses the `#[PublicPage]` attribute) to bypass Nextcloud authentication checks.

The route is already defined in ARCHITECTURE.md's Vue Router section.

**Why:** Link shares are for external parties without Nextcloud accounts. The page must be fully public. Nextcloud supports this via the PublicPage annotation.

### D6: Service Layer Architecture

```
LinkShareController (authenticated -- CRUD for secret owner)
  └── LinkShareService (business logic)
        ├── LinkShareMapper (DB access)
        ├── SecretService (ownership validation, secret existence check)
        └── EncryptionSuiteService (suite status check)

LinkShareAccessController (public -- Phase 1 + Phase 2 endpoints)
  └── LinkShareService
        └── LinkShareMapper
```

**LinkShareService methods:**
- `create(secretId, encryptedSnapshot, salt, usageLimit, expiresAt, userId): LinkShare` -- validates ownership, validates usage_limit (1-10), generates token, creates row
- `getByToken(token): LinkShare` -- validates existence, usage, expiry, failed_attempts; throws NotFoundException if any check fails
- `confirmAccess(token): LinkShare` -- increments usage_count, resets failed_attempts, deletes if usage_count == usage_limit
- `recordFailedAttempt(token): void` -- increments failed_attempts, deletes if >= 5
- `listBySecret(secretId, userId): array` -- returns link shares for a secret (no blobs)
- `delete(id, userId): void` -- validates ownership, deletes the row
- `deleteBySecretId(secretId): void` -- cascade for secret deletion
- `deleteByUserId(userId): void` -- cascade for compromise recovery

**Why:** Two controllers separate the authentication concerns: `LinkShareController` requires Nextcloud auth for CRUD, `LinkShareAccessController` is fully public. Both share the same `LinkShareService` for business logic.

### D7: API Endpoints

**Authenticated endpoints (require Nextcloud session):**

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/secrets/{secretId}/link-shares` | List link shares for a secret |
| POST | `/api/v1/secrets/{secretId}/link-shares` | Create a link share |
| DELETE | `/api/v1/link-shares/{id}` | Revoke (delete) a link share |

**Public endpoints (no auth required):**

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/public/link-shares/{token}` | Fetch encrypted blob (Phase 1) |
| POST | `/api/v1/public/link-shares/{token}/confirm` | Confirm successful decryption (Phase 2) |

The authenticated endpoints validate that the current user owns the secret referenced by the link share. The public endpoints validate only the token.

### D8: Frontend Architecture -- Pinia Store + Argon2id Module

**useLinkShareStore (Pinia):**
- State: linkShares (array per current secret), loading, createdPassword (string, transient)
- Actions:
  - `createLinkShare(secretId, usageLimit, expiresAt)`: decrypts secret, generates password, runs Argon2id, encrypts snapshot, POSTs to API, stores password in `createdPassword` for one-time display
  - `fetchLinkShares(secretId)`: loads management list from API
  - `deleteLinkShare(id)`: revokes a link share
  - `clearCreatedPassword()`: nulls `createdPassword` (called on dialog close)

**src/crypto/argon2.js:**
- `deriveAesKeyArgon2id(password, salt)`: loads `argon2-browser` WASM, runs Argon2id with fixed parameters (memory: 65536 KiB, iterations: 3, parallelism: 1, hashLength: 32), returns a `Uint8Array` key
- `encryptSnapshot(jsonString, password)`: generates salt + IV, derives key, encrypts with AES-GCM, returns `{ blob: base64, salt: base64 }`
- `decryptSnapshot(base64Blob, base64Salt, password)`: derives key from password + salt, decrypts AES-GCM, returns JSON string

**Why:** Argon2id is isolated in its own crypto module (separate from `aes.js` and `rsa.js`) because it uses WASM, not WebCrypto. The store manages the one-time password display lifecycle: `createdPassword` is set on creation and cleared when the dialog closes.

### D9: Frontend Components

| Component | Library Components Used | Purpose |
|-----------|------------------------|---------|
| `LinkShareAccess.vue` | (standalone public page) | Public page for link access: password input, Argon2id decryption, secret display |
| `LinkShareCreateDialog.vue` | NcDialog, NcInputField, NcButton | Dialog for creating a link share: usage limit selector (1-10), optional expiry date, shows generated link + password |
| `LinkShareList.vue` | CnDataTable | Table of active link shares for a secret: token (truncated), usage (count/limit), created date, expiry, delete button |
| `LinkSharePasswordDisplay.vue` | NcNoteCard, NcButton | One-time display of generated password and link URL with copy buttons |

**LinkShareAccess.vue** is a full-page component rendered at `/share/link/:token`. It does NOT use the Doriath app layout (no sidebar, no header). It shows:
1. Doriath logo and "Shared Secret" heading
2. Password input field
3. Submit button (disabled while Argon2id is running, shows spinner)
4. On success: the decrypted secret fields (name, key, login, additional_fields) in a read-only card
5. On failure: error message with retry option
6. After usage limit reached: "This link has expired" message

**LinkShareCreateDialog.vue** is opened from the SecretDetail view. It:
1. Shows a usage limit selector (NcSelect or number input, range 1-10, default 1)
2. Shows an optional expiry date picker
3. On submit: runs the full encryption flow (D4), shows LinkSharePasswordDisplay
4. The dialog MUST NOT be closeable during the encryption process (Argon2id + AES takes ~1-2 seconds)

### D10: Argon2id WASM Dependency

Add `argon2-browser` (or equivalent) as an npm dependency. This library provides Argon2id via a WASM binary compiled from the reference C implementation.

**Webpack configuration:** The WASM binary must be served as a static asset. Add a webpack rule to handle `.wasm` files:
```javascript
{
  test: /\.wasm$/,
  type: 'javascript/auto',
  loader: 'file-loader',
  options: { name: '[name].[hash].[ext]' }
}
```

The library is lazy-loaded only when link sharing features are used (creation or access page), not on initial app load. This prevents the ~200KB WASM payload from affecting vault load times.

**Why:** Argon2id is not available in WebCrypto. WASM is the only performant way to run it in the browser. The `argon2-browser` package is mature, well-maintained, and uses the reference Argon2 C implementation.

**Alternatives considered:**
- `argon2-wasm-pro`: Similar capabilities but less community adoption. Rejected for lower maturity.
- `hash-wasm`: Supports multiple algorithms including Argon2id, but larger bundle. Considered as fallback if `argon2-browser` has compatibility issues.

### D11: Token Generation -- Server-Side Entropy

Tokens are generated server-side using `bin2hex(random_bytes(16))`, producing a 32-character hexadecimal string with 128 bits of entropy. The token is the only identifier in the public URL: `https://cloud.example.com/apps/doriath/#/share/link/{token}`.

**Why:** `random_bytes()` uses the OS CSPRNG (`/dev/urandom` on Linux). Server-side generation ensures the token is never exposed to client-side JavaScript before storage. 128 bits of entropy makes brute-force token guessing infeasible (2^128 combinations).

**Alternatives considered:**
- UUID v4 as token: 122 bits of entropy (6 bits used for version/variant). Acceptable but the hex-encoded random_bytes approach is simpler and provides the full 128 bits. UUIDs also contain hyphens which complicate URL handling.
- Client-generated token: Rejected -- the client cannot be trusted to generate sufficient entropy. Server-side generation is the security best practice.

## Seed Data

Since Doriath uses its own database (not OpenRegister), seed data is handled through repair steps:

### SeedDevelopmentLinkShares (repair step -- debug mode only)

The `SeedDevelopmentLinkShares` repair step (registered only when `debug=true`) creates example link shares for development secrets. It depends on `SeedDevelopmentSecrets` from implement-secrets (which creates the example secrets) and `SeedDevelopmentData` from implement-encryption-suites (which creates the dev user's encryption suite).

Example link shares seeded:

| Secret | Token (fixed for dev) | Password (known for testing) | Usage Limit | Usage Count | Expires |
|--------|----------------------|------------------------------|-------------|-------------|---------|
| GitHub | `dev_link_github_01` | `DevLink-GitHub-2024!` | 3 | 0 | null |
| AWS Console | `dev_link_aws_01` | `DevLink-AWS-2024!` | 1 | 0 | 2026-12-31 |
| Production Database | `dev_link_db_01` | `DevLink-DB-2024!` | 5 | 2 | null |

These link shares use known test passwords so developers can test the access flow. The snapshots are encrypted using Argon2id with the known passwords. The tokens are deterministic (not random) so developers can navigate to predictable URLs during testing.

The repair step also creates one expired link share (past `expires_at`) and one that has reached its usage limit (usage_count == usage_limit) for testing edge cases. These should be auto-cleaned but serve as test fixtures if the cleanup logic has not run.

## Risks / Trade-offs

- **[Risk] Argon2id WASM performance on low-end devices** -- Argon2id with 64 MiB memory takes ~0.5-1s on modern hardware but could take 3-5s on older mobile devices or low-RAM systems. Mitigated by showing a progress spinner during key derivation. The parameters are fixed (not configurable) to maintain consistent security guarantees.

- **[Risk] Two-phase protocol race condition** -- If a user opens the link in two browser tabs simultaneously, both could fetch the blob (Phase 1) and both could confirm (Phase 2), potentially exceeding the usage limit by 1. Mitigated by using an atomic `UPDATE ... WHERE usage_count < usage_limit` in the confirm endpoint. If the update affects 0 rows, the confirm returns 404.

- **[Risk] Client-side confirmation trust** -- The server trusts the browser to call the confirm endpoint after successful decryption. A malicious client could fetch the blob without confirming, avoiding usage count increment. This is acceptable because: (1) without the password the blob is useless, (2) failed_attempts still increments on subsequent Phase 1 calls, (3) the alternative (server-side decryption) violates E2E.

- **[Risk] WASM compatibility** -- Older browsers or restricted environments may not support WebAssembly. Mitigated by checking for WASM support on the public access page and displaying a "browser not supported" message if unavailable. All modern browsers (Chrome 57+, Firefox 52+, Safari 11+, Edge 16+) support WASM.

- **[Risk] Argon2id salt stored alongside blob** -- An attacker with database access has both the encrypted blob and the Argon2id salt. This is by design -- the salt's purpose is to prevent rainbow table attacks, not to be secret. The security relies on the password strength and Argon2id's memory-hardness.

- **[Trade-off] Fixed Argon2id parameters** -- Parameters (64 MiB, 3 iterations) are hardcoded, not admin-configurable. This simplifies implementation and ensures all link shares have consistent security. The downside is that parameters cannot be tuned for specific deployment environments. A future version could store the parameters alongside the salt for per-link-share configurability.

- **[Trade-off] No password recovery** -- If the link creator forgets to share the password with the recipient, the password is unrecoverable. The only option is to revoke and create a new link. This is an intentional security property -- the password exists only in the user's browser at creation time.

## Migration Plan

1. **Database migration**: Run `occ upgrade` to execute the ISchemaWrapper migration creating the `doriath_link_shares` table
2. **Development seed**: If `debug=true`, the `SeedDevelopmentLinkShares` repair step creates example link shares
3. **No data migration**: Greenfield -- no existing link share data to migrate
4. **Frontend build**: `npm install` to add `argon2-browser` dependency, `npm run build` to compile with WASM support
5. **Rollback**: Disable the app via `occ app:disable doriath`. The link_shares table remains but is inert. Active link URLs stop working immediately (controller not loaded).

## Open Questions

- ~~Should Argon2id parameters be admin-configurable?~~ **Resolved.** Fixed parameters (64 MiB, 3 iterations, parallelism 1) for consistency and simplicity. Stored parameters per-link would allow future flexibility without breaking existing links.
- Should the public access page show the secret name (unencrypted metadata) before password entry? Current decision: No -- showing the name would reveal information about the shared secret before authentication. The page shows only "Shared Secret" and a password field.
