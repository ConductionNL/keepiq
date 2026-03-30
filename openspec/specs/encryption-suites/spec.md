# Encryption Suites Specification

**Status**: planned

**OpenSpec changes:** _(none yet)_

## Purpose

An EncryptionSuite is the cryptographic identity of a user or application within Doriath. It holds a public certificate (used to encrypt secrets for the owner) and an AES-encrypted private key (used to decrypt them). EncryptionSuites are signed by the application's internal Certificate Authority.

Every user who opens Doriath gets an EncryptionSuite. Every registered Application gets one when a CSR is submitted or a key pair is generated on their behalf.

## Data Model

### EncryptionSuite

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `owner_type` | enum | No | `user` or `application` (see ADR-002) |
| `owner_id` | string | No | Nextcloud user ID or Application ID |
| `certificate` | text | No | PEM public certificate (signed by CA intermediate) |
| `private_key` | text | Yes (AES) | PEM private key, AES-256 encrypted with AES-derived key; null for CSR-registered suites |
| `status` | enum | No | `active`, `revoked`, `compromised` |
| `revoked_at` | datetime | No | Null if never revoked |
| `revoked_reason` | string | No | Null if never revoked |
| `revoked_by` | string | No | Nextcloud user ID of the revoker; null if never revoked |
| `reinstated_at` | datetime | No | Null if never reinstated |
| `reinstated_by` | string | No | Nextcloud user ID of the reinstating admin; null if never reinstated |
| `created_at` | datetime | No | |

### CACertificate

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `type` | enum | No | `root` or `intermediate` |
| `certificate` | text | No | PEM certificate |
| `private_key` | text | Yes (AES) | PEM private key — only present for intermediate; AES-encrypted |
| `created_at` | datetime | No | |
| `expires_at` | datetime | No | Derived from certificate, stored for efficient expiry queries |
| `is_active` | bool | No | Only one intermediate is active for signing at a time |
| `revoked_at` | datetime | No | Set on forced revocation; null otherwise |
| `successor_id` | FK | No | Points to the CACertificate that replaced this one; null if none |

### SuiteMigration

Tracks in-progress and completed compromise recovery migrations. One record per migration event.

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `old_suite_id` | FK | No | The compromised EncryptionSuite being migrated from |
| `new_suite_id` | FK | No | The replacement EncryptionSuite |
| `status` | enum | No | `in_progress`, `completed`, `completed_with_errors` |
| `started_at` | datetime | No | |
| `completed_at` | datetime | No | Null while in progress |

Migration progress is self-evident from secrets: secrets still pointing to `old_suite_id` have not yet been migrated. The `SuiteMigration` record is used to determine whether a write lock is active and whether the account is in a degraded state.

## Requirements

### Requirement: Suite Creation on First Login
The system MUST automatically create an EncryptionSuite for a Nextcloud user the first time they open Doriath and provide a master password.

#### Scenario: First-time user setup
- GIVEN a Nextcloud user has no existing EncryptionSuite
- WHEN they open Doriath and provide a master password
- THEN the system MUST generate a 4096-bit RSA key pair, sign the public key with the active CA intermediate, and store the private key encrypted with the AES-derived key

### Requirement: Session Mechanism
After a user successfully enters their master password, the AES-derived key and the decrypted private key MUST remain in the browser only. The master password and AES-derived key MUST NOT be sent to the server or stored in `ISession`. See ADR-003 (revised) for the always-E2E architecture.

The browser derives the AES key from the master password, uses it to decrypt the private key blob (fetched from the API), and imports the result as a WebCrypto `CryptoKey` with `extractable: false`. This `CryptoKey` is held in a JavaScript variable — never in `localStorage` or `sessionStorage`.

The session is scoped per device. Unlocking Doriath on one device MUST NOT propagate the session to other devices. Tabs on the same device sharing the same browser context MAY share the in-memory key via a shared Pinia store (same-origin tabs).

When the session timeout elapses or all tabs of the Nextcloud instance are closed, the in-memory key MUST be cleared immediately and the user MUST be redirected to the Doriath lock screen. The lock screen is a full page — not an overlay. The API always returns encrypted blobs regardless — there is no server-side session state that could be bypassed.

The session timeout MUST be configurable per user (Nextcloud session duration, 10 minutes, or 30 minutes). The timeout is enforced client-side (the browser clears the key); the server has no session state to expire.

#### Scenario: Session expiry
- GIVEN a user's Doriath session timeout has elapsed
- WHEN any Doriath route is accessed
- THEN the browser MUST clear the in-memory CryptoKey
- AND redirect the user to the Doriath lock screen

#### Scenario: All tabs closed
- GIVEN a user has Doriath open in one or more tabs
- WHEN all tabs of that Nextcloud instance are closed
- THEN the in-memory CryptoKey MUST be lost (JavaScript memory is released)

#### Scenario: Cross-device isolation
- GIVEN a user has unlocked Doriath on device A
- WHEN they open Doriath on device B
- THEN device B MUST show the lock screen and require master password entry independently

### Requirement: Master Password Strength
The system MUST enforce a minimum strength floor on master passwords using entropy-based scoring (zxcvbn or equivalent). The floor MUST NOT be configurable below the application minimum.

The strength meter MUST provide live feedback while the user types. The submit button MUST be disabled until the configured floor is met. Feedback MUST indicate why the password is weak (too short, too guessable, common pattern, etc.).

| Setting | App minimum (hardcoded) | Admin-configurable range | Default |
|---------|------------------------|--------------------------|---------|
| Minimum length | 12 characters | 12–20 characters | 12 |
| Minimum score | zxcvbn ≥ 3 | 3–4 | 3 |

Score meaning: 3 = strong (resists online attacks); 4 = very strong (resists offline attacks).

#### Scenario: Weak password rejected
- GIVEN a user is setting a master password
- WHEN they submit a password with zxcvbn score below the configured floor
- THEN the system MUST reject it with feedback explaining why

#### Scenario: Admin raises the floor
- GIVEN an admin has set minimum score to 4 and minimum length to 20
- WHEN a user submits a password with score 3 or length below 20
- THEN the system MUST reject it

### Requirement: Master Password Change — Routine
The system MUST allow a user to change their master password for routine hygiene reasons. In this case, the RSA key pair MUST remain unchanged — only the AES wrapping of the private key changes.

#### Scenario: Routine password change
- GIVEN a user provides their current master password and a new master password
- AND the new master password meets the configured strength floor
- WHEN the change is submitted
- THEN the system MUST decrypt the private key using the current AES-derived key
- AND re-encrypt it using the new AES-derived key
- AND store the updated blob
- AND no secrets are affected

### Requirement: Master Password Change — Compromise Recovery
When a user indicates their master password has been compromised, the system MUST initiate a full key rotation: a new RSA key pair is generated, all secrets are re-encrypted, and the old EncryptionSuite is flagged as compromised.

#### Scenario: Compromise recovery initiated
- GIVEN a user selects "my master password was leaked" as the reason for changing their password
- AND provides their old master password and a new master password
- WHEN the change is submitted
- THEN the system MUST generate a new RSA key pair and EncryptionSuite
- AND create a SuiteMigration record with status `in_progress`
- AND apply a write lock to the account (no create/update operations on secrets)
- AND lock all pending SecretRequests (see secret-requests spec)
- AND begin migrating all secrets from the old suite to the new suite

### Requirement: Suite Migration
During compromise recovery, the system MUST migrate all secrets from the old EncryptionSuite to the new one. Migration is performed in the user's browser (the decrypted private keys — old for decrypt, new for encrypt — are held as WebCrypto `CryptoKey` objects in JS memory). Migration STATE is persisted server-side so that a closed tab does not lose progress.

Per-secret migration steps (all in browser):
1. Fetch the encrypted secret blob from the API
2. Decrypt using the old private key (WebCrypto)
3. Re-encrypt using the new public key (WebCrypto)
4. POST the re-encrypted blob to the API
5. Server updates the secret's `encryption_suite_id` to the new suite and sets `possibly_compromised_at`

If re-encryption of a specific secret fails, the error MUST be recorded on the secret (`migration_error`) and migration MUST continue with the remaining secrets. The user MUST be informed of failures.

After all secrets are processed:
- Link shares associated with the old suite MUST be revoked
- Locked SecretRequests MUST be unlocked and re-pointed to the new suite
- The old EncryptionSuite status MUST be set to `compromised`
- The SuiteMigration status MUST be set to `completed` or `completed_with_errors`
- The write lock MUST be released

#### Scenario: Tab closed mid-migration
- GIVEN a SuiteMigration is in progress
- WHEN the user closes all browser tabs
- THEN the write lock MUST remain active
- AND the SuiteMigration record MUST remain in `in_progress` status
- WHEN the user reopens Doriath
- THEN they MUST see a "migration paused" screen showing how many secrets remain
- AND they MUST re-enter their master password to resume
- AND only unmigrated secrets (still pointing to old_suite_id) MUST be processed

#### Scenario: Secret migration fails
- GIVEN a secret fails re-encryption during migration
- WHEN the migration run completes
- THEN the failed secret MUST have `migration_error` set
- AND the user MUST be shown a list of failed secrets with the option to retry
- AND retrying requires providing the old (compromised) master password again

#### Scenario: Retry after session ends
- GIVEN one or more secrets failed migration in a previous session
- WHEN the user chooses to retry later
- THEN the system MUST warn that secrets remaining on the compromised key are at increased risk
- AND MUST require the old master password to decrypt the still-compromised secrets
- AND on success MUST clear `migration_error` and set `possibly_compromised_at`

### Requirement: Revocation
The system MUST allow a user or administrator to revoke an EncryptionSuite. Revocation assumes the private key is intact but access should be blocked — it is an administrative action, not a key compromise. When a suite is revoked, all secrets encrypted with it become immediately inaccessible. The private key remains in the database, AES-encrypted, unchanged.

#### Scenario: Revoke suite
- GIVEN an EncryptionSuite is active
- WHEN it is revoked by a user or admin with a reason
- THEN its status MUST be set to `revoked` with `revoked_at`, `revoked_reason`, and `revoked_by`
- AND it MUST NOT be used for new encryption operations
- AND the API MUST refuse to decrypt any secret associated with this suite

### Requirement: Reinstatement
The system MUST allow an administrator to reinstate a revoked EncryptionSuite. Because revocation does not assume key compromise, reinstatement re-signs the existing public key with the active CA intermediate — no new key pair is generated and no migration is required. The user's secrets become accessible again immediately after reinstatement, requiring only their master password.

#### Scenario: Reinstate revoked suite
- GIVEN an EncryptionSuite has status `revoked`
- WHEN an administrator reinstates it
- THEN the system MUST sign a new certificate for the existing public key using the active CA intermediate
- AND update the `certificate` field with the new signed certificate
- AND set the suite status back to `active`
- AND record `reinstated_at` and `reinstated_by`
- AND the revocation audit fields (`revoked_at`, `revoked_reason`, `revoked_by`) MUST be preserved
- AND the user MUST be able to access all their secrets by entering their master password — no migration required

#### Scenario: Reinstatement not available for compromised suites
- GIVEN an EncryptionSuite has status `compromised`
- WHEN an administrator attempts to reinstate it
- THEN the system MUST return an error — compromised suites cannot be reinstated, only replaced via compromise recovery

### Requirement: Minimum Key Size
The system MUST generate RSA keys of at least 4096 bits. The minimum MUST only be allowed to increase, never decrease.

### Requirement: CA Bootstrap
The system MUST generate a private CA (root + intermediate) on first setup if no CA has been configured. If bootstrap fails, the app MUST boot in a degraded state rather than failing installation.

#### Scenario: CA bootstrap success
- GIVEN Doriath has no CA certificates
- WHEN the repair/install step runs
- THEN the system MUST generate a root certificate (20-year lifetime) and a signing intermediate certificate (3-year lifetime)
- AND store the intermediate's private key AES-encrypted in the database

#### Scenario: CA bootstrap failure
- GIVEN the bootstrap step encounters an error (database failure, insufficient entropy, etc.)
- WHEN the repair/install step completes
- THEN Doriath MUST install successfully but boot in a degraded state
- AND all Doriath routes MUST display: "Doriath cannot run without a configured Certificate Authority"
- AND the admin panel MUST show CA status as "not configured" with a retry button
- AND clicking retry MUST attempt bootstrap again

### Requirement: CA Certificate Renewal
The system MUST manage CA certificate expiry and renewal to ensure uninterrupted operation.

**Intermediate certificate (3-year lifetime):**
- The system MUST automatically renew the intermediate certificate before expiry
- All active EncryptionSuites MUST be re-signed with the new intermediate (server-side, no user action required — only the certificate wrapping changes, not the RSA key pair)
- The old intermediate MUST be retained for verification until its expiry date, then discarded
- The admin MUST be notified that auto-renewal occurred

**Root certificate (20-year lifetime):**
- The system MUST notify admins at 90, 30, and 7 days before root expiry
- Root renewal MUST be triggered manually by an administrator
- On renewal: a new root is generated, a new intermediate is signed by the new root, and all active EncryptionSuites are re-signed with the new intermediate

**Forced renewal (admin-initiated):**
- Admins MUST be able to force renewal of the intermediate at any time
- Use cases: leaked intermediate key, ownership transfer of the Nextcloud instance
- On forced renewal: new intermediate generated, all active EncryptionSuites re-signed, old intermediate immediately flagged revoked (not retained for verification)

#### Scenario: Intermediate auto-renewal
- GIVEN the active intermediate certificate is approaching expiry
- WHEN the background renewal job runs
- THEN the system MUST generate a new intermediate, re-sign all active EncryptionSuites, and notify the admin

#### Scenario: Forced intermediate renewal
- GIVEN an admin triggers forced intermediate renewal
- WHEN the operation completes
- THEN the old intermediate MUST be immediately revoked
- AND all active EncryptionSuites MUST be re-signed with the new intermediate
- AND the admin MUST be shown a confirmation of how many suites were re-signed

### Requirement: CA Health Check
The admin panel MUST display the current CA status at all times.

| Status | Meaning |
|--------|---------|
| Not configured | Bootstrap has not completed |
| Healthy | CA is active, no renewal needed soon |
| Expiring soon | Intermediate within 30 days of expiry |
| Action required | Root within 90 days of expiry, or intermediate revoked |

## User Stories

- As a new user, I want Doriath to set up my encryption automatically when I first enter my master password
- As a user, I want to choose how long my master password stays in my session so that I balance security with convenience
- As a user, I want to be redirected to a lock screen when my session expires, not an overlay I could bypass
- As a user, I want live feedback on my master password strength so that I know if it meets the requirements before submitting
- As a user, I want to change my master password for routine hygiene without affecting my stored secrets
- As a user, I want to rotate my encryption key pair if my master password was leaked, so that a compromised password cannot be used to access my secrets
- As a user, I want to be able to resume a failed key migration later, so that a browser crash does not leave me stuck
- As a user, I want to revoke my encryption suite if I suspect my private key has been compromised
- As an administrator, I want to revoke a user's encryption suite if their credentials are compromised
- As an administrator, I want to be notified when CA certificates are approaching expiry so that I can act before users are affected
- As an administrator, I want to force-renew the intermediate certificate if its key is leaked
- As an administrator, I want to retry CA bootstrap from the admin panel if it failed during installation

## Acceptance Criteria

- [ ] An EncryptionSuite is created automatically for a user on first login to Doriath
- [ ] RSA key size is at least 4096 bits
- [ ] Private key is stored AES-256 encrypted with the AES-derived key — never in plaintext
- [ ] Master password and AES-derived key never leave the browser — not sent to server or stored in ISession
- [ ] Decrypted private key is imported as WebCrypto CryptoKey with extractable: false
- [ ] CryptoKey is held in a JS variable, never in localStorage or sessionStorage
- [ ] Session timeout is configurable per user (Nextcloud session / 10 min / 30 min), enforced client-side
- [ ] Session expiry clears the in-memory CryptoKey and redirects to the lock screen (full page, not overlay)
- [ ] Closing all tabs releases JavaScript memory (CryptoKey lost)
- [ ] Unlocking Doriath on one device does not affect other devices
- [ ] Master password strength is enforced using entropy-based scoring (zxcvbn ≥ 3, length ≥ 12 by default)
- [ ] Admin can raise the strength floor up to zxcvbn score 4 and length 20
- [ ] Live strength feedback is shown while the user types
- [ ] Routine master password change re-wraps the private key without changing the RSA key pair or affecting secrets
- [ ] Compromise recovery generates a new RSA key pair and migrates all secrets
- [ ] Write lock is applied during migration and persists if the browser tab is closed
- [ ] Pending SecretRequests are locked during migration and re-pointed to the new suite on completion
- [ ] Link shares are revoked during compromise recovery
- [ ] Per-secret migration errors are recorded and surfaced to the user
- [ ] Failed secrets can be retried in a later session (with warning about increased risk)
- [ ] Old EncryptionSuite is flagged `compromised` after migration completes
- [ ] Suites can be revoked by user or admin with reason, timestamp, and revoker recorded
- [ ] Revocation audit fields are preserved on reinstatement
- [ ] Revoked suites cannot be used for new encryption or decryption
- [ ] Revoked suites can be reinstated by an admin — re-signs the existing public key, no migration required
- [ ] Reinstated suites record reinstated_at and reinstated_by
- [ ] Compromised suites cannot be reinstated
- [ ] A CA (root + intermediate) is bootstrapped on first setup if none exists
- [ ] Bootstrap failure results in degraded state, not installation failure
- [ ] Admin can retry bootstrap from the admin panel
- [ ] Intermediate certificate auto-renews at 3-year intervals; all suites are re-signed
- [ ] Root certificate renewal is admin-triggered; admins are notified at 90/30/7 days before expiry
- [ ] Forced intermediate renewal revokes the old intermediate immediately
- [ ] Admin panel shows CA health status at all times
- [ ] All certificates are signed by the active intermediate

## Open Questions

- **Forced intermediate revocation and secret compromise**: when an admin force-revokes the intermediate certificate (e.g. leaked intermediate key), should all secrets be flagged `possibly_compromised_at`? The intermediate key is used for signing certificates, not for encrypting secrets directly — but a compromised intermediate could allow forged certificates. To be decided when certificate management is specced further.

## Notes

- The AES-derived key and decrypted private key exist only in browser JS memory (WebCrypto CryptoKey). There is no server-side session state for Doriath's encryption. See ADR-003 for the always-E2E architecture and the DecryptService/EncryptService for internal Nextcloud app access.
- Multiple encryption suites per owner (key rotation beyond compromise recovery) are scoped to a future change.
- CA upload (custom CA chain) is scoped as advanced functionality.
- Cross-spec: Secret entity requires `possibly_compromised_at` (datetime) and `migration_error` (text) fields — see secrets spec.
- Cross-spec: SecretRequest entity requires an explicit `encryption_suite_id` FK to know which public certificate to use when encrypting submitted values — see secret-requests spec.
- Related ADRs: ADR-002 (polymorphic ownership), ADR-003 (encryption architecture)
