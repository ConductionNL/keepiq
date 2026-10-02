# Encryption Suites Specification

**Status**: in-progress

**OpenSpec changes:**
- `implement-encryption-suites` (2026-03-31) — Full implementation: CA bootstrap, suite lifecycle, master password session, lock screen, crypto services

## Purpose

An EncryptionSuite is the cryptographic identity of a user or application within Keepiq. It holds a public certificate (used to encrypt secrets for the owner) and an AES-encrypted private key (used to decrypt them). EncryptionSuites are signed by the application's internal Certificate Authority.

Every user who opens Keepiq gets an EncryptionSuite. Every registered Application gets one when a CSR is submitted or a key pair is generated on their behalf.

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
The system MUST automatically create an EncryptionSuite for a Nextcloud user the first time they open Keepiq and provide a master password.

#### Scenario: First-time user setup
@e2e exclude First-time suite creation requires a suite-less account that the seeded e2e fixture never produces; the vault-unlock e2e suite marks this flow test.fixme and it is covered by PHPUnit suite-creation tests instead.
- GIVEN a Nextcloud user has no existing EncryptionSuite
- WHEN they open Keepiq and provide a master password
- THEN the system MUST generate a 4096-bit RSA key pair, sign the public key with the active CA intermediate, and store the private key encrypted with the AES-derived key

### Requirement: Session Mechanism
After a user successfully enters their master password, the AES-derived key and the decrypted private key MUST remain in the browser only. The master password and AES-derived key MUST NOT be sent to the server or stored in `ISession`. See ADR-003 (revised) for the always-E2E architecture.

The browser derives the AES key from the master password, uses it to decrypt the private key blob (fetched from the API), and imports the result as a WebCrypto `CryptoKey` with `extractable: false`. This `CryptoKey` is held in a JavaScript variable — never in `localStorage` or `sessionStorage`.

The session is scoped per device. Unlocking Keepiq on one device MUST NOT propagate the session to other devices. Tabs on the same device sharing the same browser context MAY share the in-memory key via a shared Pinia store (same-origin tabs).

When the session timeout elapses or all tabs of the Nextcloud instance are closed, the in-memory key MUST be cleared immediately and the user MUST be redirected to the Keepiq lock screen. The lock screen is a full page — not an overlay. The API always returns encrypted blobs regardless — there is no server-side session state that could be bypassed.

The user MUST be able to lock the vault immediately via a "Lock vault" button in the app navigation. This clears the in-memory CryptoKey and redirects to the lock screen without waiting for the timeout.

The session timeout MUST be configurable per user (Nextcloud session duration, 10 minutes, or 30 minutes). The timeout is enforced client-side (the browser clears the key); the server has no session state to expire.

#### Scenario: Session expiry
@e2e exclude Pure client-side WebCrypto in-memory key expiry — the in-memory CryptoKey cannot be inspected or triggered via Playwright DOM interaction; covered by unit tests of the session-timeout timer logic.
- GIVEN a user's Keepiq session timeout has elapsed
- WHEN any Keepiq route is accessed
- THEN the browser MUST clear the in-memory CryptoKey
- AND redirect the user to the Keepiq lock screen

#### Scenario: All tabs closed
@e2e exclude JavaScript memory is released by the runtime when tabs are closed — not observable or triggerable via Playwright DOM; covered by code review and unit tests.
- GIVEN a user has Keepiq open in one or more tabs
- WHEN all tabs of that Nextcloud instance are closed
- THEN the in-memory CryptoKey MUST be lost (JavaScript memory is released)

#### Scenario: Cross-device isolation
@e2e exclude Multi-device isolation is a property of browser-isolated WebCrypto key storage — requires two separate browser contexts and server-state verification; covered by architecture review (ADR-003) and PHPUnit, not a single-browser Playwright flow.
- GIVEN a user has unlocked Keepiq on device A
- WHEN they open Keepiq on device B
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
@e2e exclude Admin floor configuration is tested within the admin-settings spec (admin raises minimum score/length) where the settings UI is the entry point; the lock-screen rejection itself is a server-side validation covered by PHPUnit.
- GIVEN an admin has set minimum score to 4 and minimum length to 20
- WHEN a user submits a password with score 3 or length below 20
- THEN the system MUST reject it

### Requirement: Master Password Change — Routine
The system MUST allow a user to change their master password for routine hygiene reasons. In this case, the RSA key pair MUST remain unchanged — only the AES wrapping of the private key changes.

#### Scenario: Routine password change
@e2e exclude The password-change form is rendered inside the user-settings dialog; verifying that AES key re-wrapping succeeded requires reading back the encrypted private-key blob — a crypto-API assertion, not DOM-observable. The form's UI surface is captured in user-settings::user-opens-settings.
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
@e2e exclude Verifying RSA key pair generation, SuiteMigration record creation, and write-lock application requires inspecting server-side crypto state — not DOM-observable. The recovery UI form renders in the user-settings dialog and its presence is captured in user-settings::user-opens-settings.
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
4. Decrypt the re-encrypted blob again with the NEW private key and compare it byte-for-byte with the plaintext from step 2 — see "Re-Encrypted Ciphertext Is Verified Before The Original Is Discarded". A blob that does not survive this comparison MUST NOT be sent
5. POST the verified blob to the API
6. Server updates the secret's `encryption_suite_id` to the new suite and sets `possibly_compromised_at`

If re-encryption of a specific secret fails, the error MUST be recorded on the secret (`migration_error`) and migration MUST continue with the remaining secrets. The user MUST be informed of failures.

After all secrets are processed — and only once nothing remains that nobody has attempted, per "A Migration Always Has A Way To Terminate", which also governs what happens when a record cannot be carried across at all:
- Link shares associated with the old suite MUST be revoked
- Locked SecretRequests MUST be unlocked and re-pointed to the new suite
- The old EncryptionSuite status MUST be set to `compromised`
- The SuiteMigration status MUST be set to `completed` or `completed_with_errors`
- The write lock MUST be released

#### Scenario: Tab closed mid-migration
@e2e exclude The paused-migration banner and resume flow ARE built (src/components/MigrationResumeBanner.vue). Excluded because reaching this state in Playwright needs a deliberately interrupted migration plus a second master-password generation, which requires a dedicated fixture account rather than the shared dev vault. Covered by tests/components/MigrationResumeBanner.spec.js (count, locked-vault gating, acknowledgement hand-off) and by the write-lock persistence assertions in tests/Unit/Service/MigrationServiceTest.php.
- GIVEN a SuiteMigration is in progress
- WHEN the user closes all browser tabs
- THEN the write lock MUST remain active
- AND the SuiteMigration record MUST remain in `in_progress` status
- WHEN the user reopens Keepiq
- THEN they MUST see a "migration paused" screen showing how many secrets remain
- AND they MUST re-enter their PREVIOUS master password to resume — the current one unlocks the vault but cannot read what has not moved yet
- AND only unmigrated secrets (still pointing to old_suite_id) MUST be processed

#### Scenario: Secret migration fails
@e2e exclude The failure list and retry action ARE built (CompromiseRecoveryForm.vue). Excluded because inducing a per-record failure needs ciphertext that cannot be decrypted, which no DOM path produces. Covered by tests/components/CompromiseRecoveryForm.spec.js and tests/Unit/Service/MigrationWorkServiceTest.php.
- GIVEN a secret fails re-encryption during migration
- WHEN the migration run completes
- THEN the failed secret MUST have `migration_error` set
- AND the user MUST be shown a list of failed secrets with the option to retry
- AND retrying requires providing the old (compromised) master password again

#### Scenario: Retry after session ends
@e2e exclude The retry flow IS built (CompromiseRecoveryForm handleRetry → resumeMigration). Excluded because it needs a prior run that left recorded failures, and the flag assertions are on persisted rows rather than the DOM. Covered by tests/store/migrationLoop.spec.js and tests/Unit/Service/SecretServiceTest.php.
- GIVEN one or more secrets failed migration in a previous session
- WHEN the user chooses to retry later
- THEN the system MUST warn that secrets remaining on the compromised key are at increased risk
- AND MUST require the old master password to decrypt the still-compromised secrets
- AND on success MUST clear `migration_error` and set `possibly_compromised_at`

### Requirement: Migration Covers Every Suite-Bound Store

The Suite Migration requirement speaks of migrating "all secrets". Because a user's ciphertext is bound to an EncryptionSuite in six separate stores, a migration that walks `keepiq_secrets` alone silently strands the other five. The system MUST therefore treat compromise-recovery migration as complete only when every suite-bound store has been given its disposition. Outstanding work MUST be derivable server-side from the data itself — rows still bound to `old_suite_id` — rather than from a client-reported count, so that a resumed migration knows what remains without trusting the browser.

The disposition of each store is fixed as follows. All fields listed as re-encrypted are stored as RSA ciphertext; plaintext columns (`name`, `url`, `folder_id`, `requested_fields`) are organisational metadata and MUST NOT be touched.

| Store | Suite-bound content | Disposition |
|-------|---------------------|-------------|
| `keepiq_secrets` | `key`, `login`, `additional_fields` | Re-encrypt under the new suite; re-point `encryption_suite_id` |
| `keepiq_secret_versions` | `key`, `login`, `additional_fields` (own `encryption_suite_id`) | Re-encrypt the bounded window fixed by the `secret-version-history` spec (head plus the N most recent versions, default 5); drop older versions |
| `keepiq_attachment_grants` | `wrapped_file_key` (RSA-wrapped per-file AES key) | Re-wrap the rotating owner's own grants under the new suite. Grants belonging to other recipients MUST NOT be altered |
| `keepiq_secret_requests` | No ciphertext of its own; `encryption_suite_id` selects the certificate used to encrypt future submissions | Lock for the duration of the migration, then unlock and re-point to the new suite |
| `keepiq_link_shares` | `encrypted_secret_snapshot` | Revoke (cascade), unchanged from current behaviour |
| `keepiq_emergency_contacts` | `recovery_envelope` | Invalidate, unchanged. The envelope is wrapped to the *grantee's* certificate and escrows the grantor's old private key as its plaintext, so the rotating owner cannot re-wrap it alone; the grantor MUST be prompted to re-establish emergency access (see the `emergency-access` spec) |

Re-encryption of `keepiq_secrets`, `keepiq_secret_versions` and `keepiq_attachment_grants` MUST happen in the browser under the same rules as ordinary migration: the old private key decrypts and the new public key encrypts, both as WebCrypto `CryptoKey` objects, and only ciphertext crosses the wire. RSA has a per-chunk plaintext cap (446 bytes at RSA-4096), so every value MUST be re-chunked against the new key rather than having its existing chunk framing reused.

Owner and suite scoping MUST be enforced server-side on every re-encryption write, resolving the acting user through the Nextcloud `OCP\IUserSession` the surrounding controllers already use: a write MUST be refused unless the target row's current `encryption_suite_id` is the migration's `old_suite_id` and the row is owned by the migration's owner.

#### Scenario: Attachment grants survive the rotation

@e2e exclude Attachment-grant re-wrapping is verified by unwrapping the file key with the new private key — a WebCrypto/DB assertion with no DOM surface; covered by unit tests of the migration driver and PHPUnit on the re-point endpoint.
- **GIVEN** a user owns a secret with an encrypted attachment, and their own attachment grant holds the file key wrapped under their old suite
- **WHEN** compromise recovery migration completes
- **THEN** the owner's grant MUST hold the same file key re-wrapped under the new suite and the shared ciphertext blob MUST NOT be re-uploaded or duplicated
- **AND** grants held by other recipients of that attachment MUST be unchanged

#### Scenario: Version history migrates within its bounded window

@e2e exclude Version-history migration is asserted on stored ciphertext and row counts; the version list UI shows only counts, so the migration itself is not DOM-observable. Covered by PHPUnit and migration-driver unit tests.
- **GIVEN** a secret with a head and 12 prior versions, and a migration window of 5
- **WHEN** compromise recovery migration completes
- **THEN** the head and the 5 most recent versions MUST be re-encrypted under the new suite and re-pointed
- **AND** the 7 older versions MUST be deleted
- **AND** the user MUST be told that older version history was dropped

#### Scenario: Secret requests are locked and re-pointed, not stranded

@e2e exclude The lock/re-point transition is server-side request state; the fill-in page's "temporarily unavailable" surface belongs to the secret-requests spec. Covered by PHPUnit on the request lifecycle.
- **GIVEN** a user has pending SecretRequests when they declare their master password compromised
- **WHEN** the migration starts
- **THEN** those requests MUST be set to `locked` and the fill-in link MUST report the request as temporarily unavailable
- **WHEN** the migration terminates
- **THEN** those requests MUST be unlocked and their `encryption_suite_id` MUST be the new suite

#### Scenario: A store left unprocessed blocks completion

@e2e exclude Outstanding-work detection is a server-side query with no DOM representation beyond the aggregate progress indicator; covered by PHPUnit on the completion endpoint.
- **GIVEN** a migration in which the attachment-grant pass has not yet run, so grants remain bound to `old_suite_id`
- **WHEN** the client requests completion of the migration
- **THEN** the server MUST refuse to mark the migration terminal
- **AND** the migration MUST remain `in_progress` with the write lock held

### Requirement: A Migration Always Has A Way To Terminate

A migration MUST always have a way to terminate. Completion is therefore gated on rows nobody has attempted, NOT on every row still bound to `old_suite_id`. The two are different situations and conflating them makes the write lock inescapable: a record that can never be re-encrypted would hold the migration open forever, leaving the owner permanently unable to write to their own vault.

A row is **unaccounted for** when it is still bound to `old_suite_id` and its owning secret carries no `migration_error`. The system MUST refuse to terminate a migration while any unaccounted-for row exists, because terminating locks the old suite and would take every un-reached row down with it. The refusal MUST name the remaining count and point at resuming.

A row that was attempted and recorded a failure MUST NOT block termination. Terminating with such rows present MUST require an explicit acknowledgement from the client stating how many records it accepts losing, and the count MUST match what the server observes; an absent or mismatched acknowledgement MUST be refused. This makes locking a secret out of the vault a decision the owner made, never a side-effect of a client calling completion — a run in which every record failed would otherwise silently lock an owner out of everything.

Only a failure to decrypt the EXISTING ciphertext with the old key may be recorded as a per-record failure. A re-encryption that does not survive its round-trip check MUST NOT be recorded, because the original decrypted successfully and is therefore readable: the fault lies in the new key material, it will recur on every record, and the run MUST stop instead. It follows that finalisation can only ever remove access from rows that were already unreadable under the old key.

#### Scenario: Unattempted rows refuse termination and point at resuming

@e2e exclude Server-side query and status transition; covered by PHPUnit on the completion path.
- **GIVEN** a migration whose client stopped before processing every record, leaving rows with no `migration_error`
- **WHEN** completion is requested
- **THEN** the server MUST refuse, MUST leave the old suite `active`, and MUST keep the migration `in_progress`
- **AND** the refusal MUST report how many records remain and state that the migration can be resumed

#### Scenario: An unrecoverable record does not trap the vault

@e2e exclude Terminal status transition and suite locking are server-side; covered by PHPUnit on the completion path.
- **GIVEN** a migration in which every remaining row on `old_suite_id` has a recorded `migration_error`
- **WHEN** completion is requested WITHOUT an acknowledgement
- **THEN** the server MUST refuse and MUST state how many records would lose access
- **WHEN** completion is requested WITH an acknowledgement matching that count
- **THEN** the migration MUST terminate as `completed_with_errors`, the old suite MUST be locked, and the write lock MUST be released
- **AND** the response MUST identify the secrets that lost access

#### Scenario: A round-trip failure halts rather than sacrificing the record

@e2e exclude Injected at the crypto layer; no DOM path induces it. Covered by unit tests of the migration pipeline.
- **GIVEN** a record whose existing ciphertext decrypts correctly but whose re-encryption does not survive the round-trip check
- **WHEN** the migration processes that record
- **THEN** the failure MUST NOT be recorded as a per-record migration failure
- **AND** the run MUST stop so the new key material can be investigated
- **AND** records already committed MUST remain valid, each having been verified before its own commit

### Requirement: Suite Resolution Is Deterministic During A Migration

For the duration of a compromise-recovery migration an owner legitimately has two `active` EncryptionSuites: the new one, and the old one which must stay readable so the browser can decrypt what it is migrating. Every resolution of "the owner's active suite" MUST therefore be deterministic and MUST select the most recently created active suite, which is always the write target.

Resolution MUST NOT fail merely because more than one suite is active. Treating a legitimate mid-migration state as an error made ordinary writes fail, and reported the one diagnosis that was certainly false — that the owner had no active suite when in fact they had two.

Listings of an owner's suites MUST likewise be ordered newest-first, so that clients selecting "the active suite" from a list bind to the same suite the server would resolve. A client bound to the old suite mid-migration holds the wrong private key for verification, which makes a resumed migration fail on every record.

The system MUST refuse to begin a compromise recovery while a migration is already in progress. Starting a second rotation strands everything the first had not yet reached on a suite that is neither endpoint of the new migration, and no resume can reach it.

#### Scenario: Writes keep working mid-migration

@e2e exclude Suite resolution is a server-side query; covered by PHPUnit.
- **GIVEN** a compromise-recovery migration is in progress, so the owner has two active suites
- **WHEN** the owner's active suite is resolved
- **THEN** the newest active suite MUST be returned rather than an error

#### Scenario: A second rotation is refused

@e2e exclude Guard is server-side and returns a status code; covered by PHPUnit on the recovery endpoint.
- **GIVEN** a migration is already in progress
- **WHEN** the owner submits another compromise recovery
- **THEN** the system MUST refuse, MUST create no suite, and MUST start no migration
- **AND** the error MUST direct the owner to resume or abort the existing migration

### Requirement: Re-Encrypted Ciphertext Is Verified Before The Original Is Discarded

The system MUST prove that freshly produced ciphertext decrypts back to the original plaintext under the new private key **before** the original ciphertext is overwritten or discarded. A round-trip that does not yield a byte-identical plaintext MUST be treated as a per-record migration failure: the original ciphertext MUST be left intact, the failure MUST be recorded, and the migration MUST continue with the remaining records.

This applies to every re-encrypted store. No code path may destroy old ciphertext on the strength of a successful encrypt call alone — the only acceptable evidence is a successful decrypt of the new blob.

#### Scenario: Round-trip mismatch leaves the original intact

@e2e exclude A deliberately corrupted re-encryption is injected at the crypto layer; there is no DOM path to induce it. Covered by unit tests of the migration driver.
- **GIVEN** a record whose re-encrypted blob does not decrypt back to the original plaintext
- **WHEN** the migration processes that record
- **THEN** the original ciphertext and its `encryption_suite_id` MUST be left unchanged
- **AND** the record MUST be counted as a migration failure
- **AND** the migration MUST continue with the remaining records

#### Scenario: Verified re-encryption is committed

@e2e exclude Verification is an in-browser WebCrypto assertion preceding the POST; not DOM-observable. Covered by unit tests of the migration driver.
- **GIVEN** a record whose re-encrypted blob decrypts back to the original plaintext under the new private key
- **WHEN** the migration processes that record
- **THEN** the new ciphertext MUST be written and the row re-pointed to the new suite in the same operation

### Requirement: Migration Refuses To Start On An Unusable New Suite

The system MUST refuse to begin a compromise-recovery migration unless the new EncryptionSuite's signed certificate carries the public key that was submitted for it. The check MUST compare the public key inside the issued certificate against the submitted public key (the `certCarriesPublicKey` modulus comparison already implemented in `CertificateAuthorityService`), and MUST run before any record is re-encrypted.

If the check fails, the system MUST NOT create a SuiteMigration record, MUST NOT apply the write lock, MUST NOT alter the old suite's status, and MUST report a distinct error telling the user their vault is untouched and safe to retry.

#### Scenario: Certificate does not carry the submitted key

@e2e exclude The failure requires forcing a certificate/public-key mismatch at the CA layer; no DOM path induces it. Covered by PHPUnit on the recovery endpoint.
- **GIVEN** a compromise recovery in which the issued certificate does not carry the submitted public key
- **WHEN** the user submits the recovery
- **THEN** the system MUST refuse with a distinct error and create no SuiteMigration record
- **AND** the old suite MUST remain `active` with no write lock applied
- **AND** no record MUST have been re-encrypted

#### Scenario: Precondition passes and migration proceeds

@e2e exclude Certificate/public-key agreement is a server-side crypto assertion; covered by PHPUnit on the recovery endpoint.
- **GIVEN** a compromise recovery in which the issued certificate carries the submitted public key
- **WHEN** the user submits the recovery
- **THEN** the SuiteMigration record MUST be created with status `in_progress` and the write lock MUST be applied

### Requirement: Compromise Recovery States That Regained Access Is Not An All-Clear

When a user declares their master password compromised, the system MUST tell them, before they confirm and again once migration terminates, that they should rotate **every secret value** in the vault. The wording MUST make clear that migrating the secrets to a new key restores *access* only, so that they can visit each site and set a new password in an orderly fashion — it MUST NOT be presented as an all-clear, a fix, or a state of safety.

The system MUST NOT report success in terms that imply the vault is secure again. Migration progress and the terminal outcome MUST be presented inside the recovery dialog, alongside this warning and the count of records still awaiting rotation.

#### Scenario: Warning shown before the user confirms

- **GIVEN** a user has opened the compromise-recovery form
- **WHEN** the form is displayed
- **THEN** it MUST state that every secret value should be rotated, and that key rotation restores access so the user can change those values — not that the secrets are safe

#### Scenario: Terminal message is not an all-clear

- **GIVEN** a compromise-recovery migration that has terminated
- **WHEN** the outcome is presented in the recovery dialog
- **THEN** it MUST report how many records were migrated and how many failed
- **AND** it MUST repeat that the migrated secret values are still to be considered exposed and MUST be rotated at their source
- **AND** it MUST NOT claim the vault is now secure

#### Scenario: Progress is visible inside the recovery dialog

- **GIVEN** a compromise-recovery migration is running
- **WHEN** the user watches the recovery dialog
- **THEN** it MUST show live progress and MUST NOT report completion until the migration has actually terminated

### Requirement: A Plain Create Refuses To Mint A Second Active Suite

`POST /api/v1/suites` MUST refuse, with `409 Conflict`, when the owner already has an `active` EncryptionSuite. The frontend hides the setup form once a suite exists, but that is presentation: the API MUST enforce the invariant itself, because a session cookie or app password reaches the endpoint without the form.

A second suite minted this way is silent data loss. Suite resolution selects the most recently created active suite, so new secrets are sealed to the new key while the owner keeps unlocking with the master password of the old one — the new records decrypt for nobody, and nothing reports an error at the time it happens. Two browser tabs left on the first-setup screen are enough to cause it, which makes it reachable by accident rather than only by a crafted request.

Malformed key material MUST still be reported as `400 Bad Request` even when the owner already has an active suite. Two things are wrong with such a request and the response must name the one the caller can act on: "you already have a suite" tells a client nothing about the unparseable key it sent. Validity of the request is judged before the state of the world, which means the refusal may discard an already-issued certificate — acceptable, because issuing one performs no write and consumes no serial.

This governs the PLAIN create path only. A compromise recovery legitimately creates a successor while the old suite stays active — see "Suite Resolution Is Deterministic During A Migration" — so the refusal MUST be something that path can opt out of explicitly, and MUST NOT be expressed as a database uniqueness constraint on `(owner_type, owner_id)` where `status = 'active'`. Such a constraint would make key rotation impossible and contradicts that requirement.

#### Scenario: A second plain create is refused

@e2e exclude The setup form is unreachable once a suite exists, so the flow cannot be driven from the DOM; the guard is server-side and covered by PHPUnit on the service and the controller.
- **GIVEN** an owner already has an active EncryptionSuite
- **WHEN** a plain create is submitted for that owner
- **THEN** the system MUST refuse with `409 Conflict`
- **AND** it MUST NOT insert a suite

#### Scenario: A bad key is a client error even when a suite exists

@e2e exclude Server-side status-code contract; covered by PHPUnit and by the Newman contract collection.
- **GIVEN** an owner already has an active EncryptionSuite
- **WHEN** a plain create is submitted with an unparseable public key
- **THEN** the system MUST refuse with `400 Bad Request`, not `409 Conflict`

#### Scenario: Compromise recovery still creates its successor

@e2e exclude Server-side; covered by PHPUnit on the recovery endpoint.
- **GIVEN** an owner has an active EncryptionSuite and no migration in progress
- **WHEN** they submit a compromise recovery
- **THEN** the successor suite MUST be created even though the old one is still active

### Requirement: Revocation
The system MUST allow a user or administrator to revoke an EncryptionSuite. Revocation assumes the private key is intact but access should be blocked — it is an administrative action, not a key compromise. When a suite is revoked, all secrets encrypted with it become immediately inaccessible. The private key remains in the database, AES-encrypted, unchanged.

#### Scenario: Revoke suite
@e2e exclude Server-side effects of revocation (status/revoked_at/revoked_reason/revoked_by, refusal to decrypt) are not DOM-observable; covered by PHPUnit EncryptionSuiteServiceTest::testRevokeSuiteSuccess, EncryptionSuiteControllerTest::testRevokeReturnsSuite and SecretControllerTest::testShowRevokedSuiteForbidden. The client half — that the reason reaches the API and that revoking evicts the offline copy, which is the security invariant no server-side test can see — is covered by tests/store/encryptionSuite.spec.js. Both suites run in this repo's CI (phpunit.xml "Unit Tests"; vitest include glob tests/store/**). NOTE the previous wording was false twice over: it claimed "No suite-revocation UI is built in v0.1" when src/App.vue ships a "Revoke encryption suite" button, a confirmation NcNoteCard and a mandatory reason field gating submit; and it claimed verification by "the Postman collection", which contains NO revoke request at all — its only occurrence of "revoke" is inside an error-message string. A full Playwright run of the destructive flow is still worth adding; it is not claimed here.
- GIVEN an EncryptionSuite is active
- WHEN it is revoked by a user or admin with a reason
- THEN its status MUST be set to `revoked` with `revoked_at`, `revoked_reason`, and `revoked_by`
- AND it MUST NOT be used for new encryption operations
- AND the API MUST refuse to decrypt any secret associated with this suite

### Requirement: Reinstatement
The system MUST allow an administrator to reinstate a revoked EncryptionSuite. Because revocation does not assume key compromise, reinstatement re-signs the existing public key with the active CA intermediate — no new key pair is generated and no migration is required. The user's secrets become accessible again immediately after reinstatement, requiring only their master password.

#### Scenario: Reinstate revoked suite
@e2e exclude No suite-reinstatement UI is built in v0.1; reinstatement is an API-only action (POST /api/v1/suites/{id}/reinstate) verified by PHPUnit, not a Playwright flow.
- GIVEN an EncryptionSuite has status `revoked`
- WHEN an administrator reinstates it
- THEN the system MUST sign a new certificate for the existing public key using the active CA intermediate
- AND update the `certificate` field with the new signed certificate
- AND set the suite status back to `active`
- AND record `reinstated_at` and `reinstated_by`
- AND the revocation audit fields (`revoked_at`, `revoked_reason`, `revoked_by`) MUST be preserved
- AND the user MUST be able to access all their secrets by entering their master password — no migration required

#### Scenario: Reinstatement not available for compromised suites
@e2e exclude API-level error contract (HTTP 422 on POST /reinstate for compromised suite) — no UI surface; covered by PHPUnit.
- GIVEN an EncryptionSuite has status `compromised`
- WHEN an administrator attempts to reinstate it
- THEN the system MUST return an error — compromised suites cannot be reinstated, only replaced via compromise recovery

### Requirement: Minimum Key Size
The system MUST generate RSA keys of at least 4096 bits. The minimum MUST only be allowed to increase, never decrease.

#### Scenario: Generated key meets the minimum size
@e2e exclude Key bit-length is not browser-observable: under ADR-003 the keypair is generated in the browser and only the PUBLIC half is ever transmitted, so no DOM surface reports the modulus size. Covered by PHPUnit in tests/Unit/Service/CertificateIssuanceServiceTest.php::testIssuedCertificatePreservesA4096BitBrowserKeyWithoutThePrivateHalf, which submits a real RSA-4096 public key with no private half and asserts the issued certificate carries that exact modulus at 4096 bits. That test runs in the "Unit Tests" suite of phpunit.xml. The GENERATION side — the requirement's actual subject, since ADR-003 puts keygen in the browser — is covered by tests/vitest/rsa.spec.js "generateKeyPair — minimum key size", which generates a real key and measures both the WebCrypto modulusLength and the modulus of the exported DER, asserted as `>= 4096` to match "the minimum MUST only be allowed to increase, never decrease". Verified 2026-08-11 that this closes a real blind spot: setting RSA_KEY_BITS to 2048 leaves all thirteen pre-existing rsa.spec.js tests GREEN, including the round-trip block named "RSA-4096" and the generateKeyPair PEM-shape test, because PEM shape and re-importability hold identically for a 2048-bit key. NOTE the earlier wording here ("enforced in the key-generation service") described a server-side check that does not exist and named no test; when written, no test in this repo asserted a generated key's bit length and CertificateIssuanceService had no test class at all. The guard that actually holds is the issuance-time reroute in CertificateIssuanceService::signPublicKey — verified 2026-08-11 to be live, not vestigial: removing it makes PHP 8.4/OpenSSL mint a throwaway keypair for a public-only 4096-bit key, which the final invariant then refuses.
- GIVEN the system generates a new RSA key pair
- WHEN the key is created
- THEN the key size MUST be at least 4096 bits
- AND any configured minimum MUST only increase, never decrease

### Requirement: Certificate Distinguished Name
All certificates issued by Keepiq MUST include a complete X.509 Distinguished Name with default organizational fields (C=NL, ST=Noord-Holland, L=Amsterdam, O=Conduction, OU=Keepiq). The `commonName` MUST identify the certificate owner:

- For user certificates: the federated cloud ID (e.g. `admin@nextcloud.local`) if available, otherwise the Nextcloud user ID
- For application certificates: the application ID
- For CA certificates: `Keepiq Root CA` or `Keepiq Intermediate CA`

When a certificate is re-signed during CA renewal, the original `commonName` MUST be preserved.

#### Scenario: Issued certificate carries the full DN
@e2e exclude Server-side certificate-issuance contract — DN fields and commonName are set by the signing service; covered by PHPUnit, not browser-observable.
- GIVEN the system issues a certificate for a user, application, or CA
- WHEN the certificate is signed
- THEN it MUST include the complete X.509 Distinguished Name with the default organizational fields
- AND the `commonName` MUST identify the certificate owner, and MUST be preserved when re-signed during CA renewal

### Requirement: CA Bootstrap
The system MUST generate a private CA (root + intermediate) on first setup if no CA has been configured. If bootstrap fails, the app MUST boot in a degraded state rather than failing installation.

#### Scenario: CA bootstrap success
@e2e exclude CA bootstrap runs during the Nextcloud repair/install step (PHP Repair class) — not a browser-visible action; verified by PHPUnit and by the CA-healthy state observable in admin-settings.
- GIVEN Keepiq has no CA certificates
- WHEN the repair/install step runs
- THEN the system MUST generate a root certificate (20-year lifetime) and a signing intermediate certificate (3-year lifetime)
- AND store the intermediate's private key AES-encrypted in the database

#### Scenario: CA bootstrap failure
@e2e exclude Bootstrap failure triggers a degraded-state server response; the observable UI outcome ("not configured" + retry button) is covered by the admin-settings::ca-not-configured scenario.
- GIVEN the bootstrap step encounters an error (database failure, insufficient entropy, etc.)
- WHEN the repair/install step completes
- THEN Keepiq MUST install successfully but boot in a degraded state
- AND all Keepiq routes MUST display: "Keepiq cannot run without a configured Certificate Authority"
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
@e2e exclude Auto-renewal runs as a background cron job — not triggered or observable via Playwright DOM; verified by PHPUnit and the CA-health state visible in admin-settings.
- GIVEN the active intermediate certificate is approaching expiry
- WHEN the background renewal job runs
- THEN the system MUST generate a new intermediate, re-sign all active EncryptionSuites, and notify the admin

#### Scenario: Forced intermediate renewal
@e2e exclude The "Force renew intermediate" button and its result are tested within admin-settings::force-intermediate-renewal where the admin settings page is the UI entry point.
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

#### Scenario: Admin panel reflects current CA status
@e2e exclude CA health status is rendered inside the admin settings page; the admin-facing display is covered by the admin-settings spec scenarios.
- GIVEN an administrator opens the Keepiq admin panel
- WHEN the CA health status is evaluated
- THEN the panel MUST display the current status (Not configured, Healthy, Expiring soon, or Action required)

### Requirement: Administrator Force-Revocation

An administrator MUST be able to revoke any EncryptionSuite by id — user-owned or application-owned — through `POST /api/v1/suites/{id}/force-revoke`, without producing the vault-key proof the owner path requires. The vault is zero-knowledge (see ADR-003): the server never holds a usable private key, so an administrator cannot sign the revoke challenge. Administrator revocation is therefore the *only* revocation path for a locked-out owner (forgotten master password), a de-authorised departure, or a compromise, and the only revocation path of any kind for an application-owned suite, which has no human owner to produce a proof.

The endpoint MUST be guarded by BOTH:

- `#[AuthorizedAdminSetting(AdminSettings::class)]` — administrator only, mirroring the existing admin-only `reinstate()`; a non-administrator MUST be rejected by Nextcloud middleware before the controller body runs.
- `#[PasswordConfirmationRequired]` — Nextcloud sudo mode. The administrator re-confirms their **own** account password; there is no vault key to prove. A stale or missing sudo confirmation MUST cause Nextcloud to refuse the request before the controller body runs.

The endpoint MUST reuse the owner-agnostic `EncryptionSuiteService::revokeSuite()`, which records the acting administrator (resolved via `OCP\IUserSession`) as `revokedBy` and dispatches `EncryptionSuiteRevokedEvent` so the existing destructive cascade (`EncryptionSuiteRevokedListener`) runs: the owner's inbound `ShareTarget`s are deleted, their temporary delegations promoted, and their emergency envelopes cleared.

The administrator MUST supply a **required, free-form** `reason`. An empty or missing reason MUST be rejected. The reason MUST be stored in the existing `revoked_reason` `STRING(255)` column — no new column and no migration.

Whether the revoked suite's secrets are treated as **compromised** MUST be an explicit, transient request parameter `markCompromised` (default `false`), carried only in the request and **never persisted** as a suite column. The compromise decision is a human, situational judgment, and MUST NOT be derived from the free-form reason text.

The `SUITE_REVOKED` audit event's metadata MUST carry `{ reason, markCompromised, emergencyContactsDestroyed }`; the `AuditEventTypes` metadata whitelist for `SUITE_REVOKED` MUST permit those three keys.

#### Scenario: Administrator force-revokes a locked-out owner's suite (forgotten password)

- **GIVEN** a user is locked out of their vault (forgotten master password) and cannot produce a vault-key proof
- **WHEN** an authenticated administrator, having passed sudo confirmation, calls `POST /api/v1/suites/{id}/force-revoke` with a non-empty `reason` and `markCompromised=false`
- **THEN** the suite MUST be revoked with `revokedBy` set to the administrator and `revoked_reason` set to the supplied reason
- **AND** no compromise cascade MUST run (secrets MUST NOT be flagged `possibly_compromised_at` and no `suite_compromise` rotation flags MUST be raised)
- **AND** the user MUST be able to re-onboard with a fresh suite through the existing onboarding flow

#### Scenario: Administrator de-authorises a departing user without marking compromise

- **GIVEN** a departing user whose secrets are long, generated, non-memorable passwords
- **WHEN** the administrator force-revokes the user's suite with `markCompromised=false`
- **THEN** the suite MUST be revoked and no compromise cascade MUST run
- **AND** the response MUST surface a warning that the revoked user may still know these secrets and that rotation may be warranted, leaving the rotation decision to the administrator

#### Scenario: Administrator marks the revocation as a compromise

- **GIVEN** a suite whose private key or master password is believed to be in an attacker's hands
- **WHEN** the administrator force-revokes the suite with `markCompromised=true`
- **THEN** every secret sealed under the suite (`SecretMapper::findByEncryptionSuiteId`) MUST be flagged `possibly_compromised_at`
- **AND** `suite_compromise` rotation flags MUST be raised for those secrets via `RotationPolicyService`/`RotationFlagService::flagCompromisedSecrets` (idempotent)
- **AND** the affected owners MUST be notified with the existing `secret_compromised` notification subject
- **AND** the cascade MUST run on the revoke path itself (scoped to the revoked suite), not via a `SuiteMigrationCompletedEvent`

#### Scenario: A non-administrator is refused

- **GIVEN** an authenticated non-administrator user
- **WHEN** they call `POST /api/v1/suites/{id}/force-revoke` on any suite id
- **THEN** Nextcloud's `AuthorizedAdminSetting` guard MUST reject the request before the controller body runs
- **AND** the suite MUST NOT be revoked

#### Scenario: Sudo confirmation is required

- **GIVEN** an administrator whose password-confirmation (sudo) window has expired
- **WHEN** they call `POST /api/v1/suites/{id}/force-revoke`
- **THEN** the `PasswordConfirmationRequired` guard MUST refuse the request until the administrator re-confirms their own account password
- **AND** the suite MUST NOT be revoked until sudo is satisfied

#### Scenario: A missing reason is rejected

- **GIVEN** an administrator who has passed the admin and sudo guards
- **WHEN** they call `POST /api/v1/suites/{id}/force-revoke` with an empty or missing `reason`
- **THEN** the request MUST be rejected and the suite MUST NOT be revoked

#### Scenario: An application-owned suite is force-revoked

- **GIVEN** an application-owned EncryptionSuite (id `00000000-0000-0000-0000-000000000000`) that has no human owner able to produce a vault-key proof
- **WHEN** an administrator force-revokes it with a non-empty `reason`
- **THEN** the same endpoint MUST revoke it via `revokeSuite()`, recording the administrator as `revokedBy`
- **AND** no owner-side vault-key proof MUST be required

#### Scenario: Emergency access is cleared unconditionally and its count audited, not gated

- **GIVEN** a suite with one or more usable emergency contacts
- **WHEN** an administrator force-revokes it
- **THEN** the revocation MUST proceed and the emergency access MUST be cleared unconditionally (revocation is authoritative — unlike the owner path, no `acceptEmergencyLoss` gate blocks it)
- **AND** the count of destroyed usable emergency contacts (`EmergencyEnvelopeInvalidationService::countUsableForGrantorSuite`) MUST be recorded in the `SUITE_REVOKED` audit metadata as `emergencyContactsDestroyed` and surfaced to the administrator as an informational warning
- **AND** the emergency contacts' identities MUST NOT cross the wire — only the count

### Requirement: A Compromise Force-Revoke Contains The Account
A force-revoke with `markCompromised: true` MUST contain everything the compromised key could reach, not only flag the secrets sealed under it. The system MUST collect the blast radius of every suite it is about to revoke (the named suite and, during an in-progress migration, the other end) BEFORE it revokes any of them, because the revoke cascade deletes the ShareTargets and invalidates the emergency contacts the lookup reads (keepiq#864). The revoke cascade MUST sweep only the ShareTargets of copies sealed under the suite being revoked.

After the revoke the system MUST:

- stamp and flag every secret in the blast radius and, for a shared copy, its source, and warn each affected owner once with the first secret's name and the number of other affected secrets (keepiq#875);
- warn every user who holds a copy of the revoked user's own secrets, and every grantor whose `approved` emergency grant is sealed to a revoked suite, because that envelope escrows the grantor's private key (keepiq#872);
- revoke the revoked user's link shares and passkeys through the same helper the owner's compromise recovery uses (keepiq#858);
- end every Nextcloud session and app password of the revoked user (keepiq#860).

Each step MUST contain its own failure: a failure on one secret, one notification or one cleanup step MUST NOT stop the others. The number of failed steps MUST be returned in the response as `cascade.failed`, with `cascadeIncomplete: true` when it is above zero, so the administrator is not told containment ran when part of it did not (keepiq#863).

Every force-revoke, compromise or not, that deletes usable emergency contacts MUST notify the suite's owner with the number deleted (both ends of a terminated migration counted together), because revocation leaves the owner nothing to look at afterwards (keepiq#876). The response MUST report the other end's count as `alsoRevokedEmergencyContactsDestroyed` (keepiq#877).

#### Scenario: A copy on the second suite of a migration warns its source owner
@e2e exclude Needs two vault users and an open migration; covered by PHPUnit on CompromiseContainmentService with the real revoke listener.
- **GIVEN** user A holds a copy of C's secret, sealed under B, the new end of A's in-progress migration
- **WHEN** an administrator force-revokes A's old suite with `markCompromised: true`
- **THEN** C MUST be warned about the source secret
- **AND** the source secret MUST be stamped `possibly_compromised_at` and flagged for rotation

#### Scenario: One failing notification does not stop the cascade
@e2e exclude Failure injection; covered by PHPUnit on CompromiseContainmentService and EncryptionSuiteController.
- **GIVEN** the notification for the first owner fails
- **WHEN** the compromise cascade runs
- **THEN** the second owner's secrets MUST still be stamped, flagged and warned
- **AND** the response MUST carry `cascadeIncomplete: true`

#### Scenario: An owner with several affected secrets is told how many
@e2e exclude Notification content; covered by PHPUnit on CompromiseContainmentService and KeepiqNotifier.
- **GIVEN** three of A's secrets are in the blast radius
- **WHEN** the compromise cascade runs
- **THEN** A MUST get one notification naming the first secret and counting the two others

#### Scenario: Grantors and share recipients are warned
@e2e exclude Needs several vault users; covered by PHPUnit on CompromiseContainmentService.
- **GIVEN** D designated A as emergency contact and approved A's request, and B holds a copy of A's secret
- **WHEN** an administrator force-revokes A's suite with `markCompromised: true`
- **THEN** D MUST be warned that their vault may be exposed
- **AND** B MUST be warned about the copy

#### Scenario: The account is contained
@e2e exclude Ends the sessions of the user under test; covered by PHPUnit on CompromiseContainmentService.
- **GIVEN** A has a link share and an active session
- **WHEN** an administrator force-revokes A's suite with `markCompromised: true`
- **THEN** A's link shares and passkeys MUST be revoked
- **AND** every session and app password of A MUST be invalidated

#### Scenario: The owner learns their emergency contacts are gone
@e2e exclude Notification side effect; covered by PHPUnit on CompromiseContainmentService and EncryptionSuiteController.
- **GIVEN** A's suite has two usable emergency contacts
- **WHEN** an administrator force-revokes it, with or without `markCompromised`
- **THEN** A MUST be notified that two emergency contacts were deleted

### Requirement: A Suite Revoked As Compromised Cannot Be Reinstated
Reinstating a suite re-opens every secret under its key. The admin `reinstate()` endpoint MUST therefore carry `#[PasswordConfirmationRequired]` like force-revoke does, and the admin UI MUST run the password confirmation before the request (keepiq#865).

The system MUST refuse to reinstate a suite whose last `SUITE_REVOKED` audit entry carries `markCompromised: true`. The compromise decision stays off the suite row (ADR-005), so the audit entry is the record. When no `SUITE_REVOKED` entry can be found for the suite, the system MUST refuse as well: nothing shows the revocation was a harmless one. The system MUST also refuse when the suite's owner already has another active suite, because a reinstate would leave them with two. Each refusal MUST answer `409` with `error` set to `revoked_as_compromised` or `owner_has_active_suite`, and MUST change nothing.

The admin UI MUST NOT offer Reinstate for a suite it has just revoked as compromised.

#### Scenario: Reinstating a compromise revoke is refused
@e2e exclude Server-side refusal on an admin API route behind sudo; covered by PHPUnit on EncryptionSuiteService and EncryptionSuiteController, and vitest on AdminSuiteSection.
- **GIVEN** an administrator force-revoked suite S with `markCompromised: true`
- **WHEN** an administrator reinstates S
- **THEN** the system MUST refuse with `409` and `error: revoked_as_compromised`
- **AND** S MUST stay revoked

#### Scenario: Reinstating next to an active suite is refused
@e2e exclude Server-side refusal; covered by PHPUnit on EncryptionSuiteService.
- **GIVEN** suite S was revoked without compromise and its owner has since set up a new active suite
- **WHEN** an administrator reinstates S
- **THEN** the system MUST refuse with `409` and `error: owner_has_active_suite`

#### Scenario: No Reinstate button after a compromise revoke
@e2e exclude Covered by vitest on AdminSuiteSection; the live flow needs sudo.
- **GIVEN** an administrator just force-revoked a suite with `markCompromised: true`
- **WHEN** the result is shown
- **THEN** the Reinstate button MUST NOT be shown

### Requirement: Re-Enrolment After A Revocation Requires A Fresh Password Confirmation
A user whose suites are all revoked or replaced, with none active, MUST NOT be able to enrol a new suite with only a session. `POST /api/v1/suites` MUST refuse such a user with `403` and `error: reauthentication_required`, and the user MUST enrol through `POST /api/v1/suites/reenrol`, which carries `#[PasswordConfirmationRequired]` (keepiq#860). Without this, a stolen session that survived an administrator's containment could enrol a key pair it owns and receive every new share and emergency designation meant for the user. The browser MUST confirm the password and retry on the re-enrol route when it gets that refusal.

Accounts that cannot confirm a password (single sign-on) pass the sudo guard. For them the protection is that a compromise force-revoke ends every session and app password (see Requirement: A Compromise Force-Revoke Contains The Account).

#### Scenario: A session alone cannot replace a revoked identity
@e2e exclude Needs a revoked user session; covered by PHPUnit on EncryptionSuiteController and vitest on the encryptionSuite store.
- **GIVEN** user A's only suite was force-revoked
- **WHEN** a session of A posts a new suite to `/api/v1/suites`
- **THEN** the system MUST refuse with `403` and `error: reauthentication_required`
- **AND** MUST NOT create a suite

#### Scenario: Re-enrolment after a password confirmation
@e2e exclude Needs the Nextcloud password confirmation dialog; covered by PHPUnit and vitest.
- **GIVEN** user A's only suite was revoked
- **WHEN** A confirms their Nextcloud password and posts the new suite to `/api/v1/suites/reenrol`
- **THEN** the system MUST create the suite

### Requirement: A Suite In An In-Progress Migration Cannot Be Revoked
The system MUST refuse to revoke a suite, by an administrator's force-revoke that is not marked as a compromise or by its owner, while that suite is the old or the new end of a key migration that is still `in_progress` (keepiq#803). Revoking the old end blocks the reads the owner's browser needs to re-encrypt; revoking the new end makes records that were already re-encrypted, or are being written, unreadable. Either way the migration and the vault write lock would stay `in_progress` with no way to finish. The refusal MUST happen before anything is changed, MUST answer `409` with `error: migration_in_progress`, and MUST say that the migration has to be completed or aborted first.

A force-revoke marked as a compromise (`markCompromised: true`) is the exception. The owner's abort is refused once any record has moved, and every migration route is owner-only, so whoever holds the session and the leaked password could otherwise keep the administrator's containment blocked for good by leaving a migration open. A compromise force-revoke therefore MUST NOT be refused for an in-progress migration. Instead it MUST revoke the migration's other end as compromised too, because during a compromise either end may be the one the attacker controls, and only then end the migration (status `terminated`, which releases the write lock and unlocks the SecretRequests the migration locked, leaving them on the old suite). A SecretRequest whose suite is no longer `active` MUST NOT be shown or filled through its public link: the fill page would otherwise hand out the certificate of a suite revoked as compromised, possibly one whose private key the attacker holds. This holds whether or not a migration was open. Ending it last means that if revoking the other end fails, a retry of the force-revoke still finds the open migration and completes the containment.

#### Scenario: Force-revoke of a suite mid-migration is refused
@e2e exclude Server-side refusal on an admin API route; covered by PHPUnit on EncryptionSuiteController and MigrationService.
- **GIVEN** user A's suite is the old or the new end of a migration in state `in_progress`
- **WHEN** an administrator force-revokes that suite
- **THEN** the system MUST refuse with `409` and `error: migration_in_progress`
- **AND** the suite, its emergency contacts and the migration MUST be unchanged

#### Scenario: A compromise force-revoke ends an open migration instead of being blocked
@e2e exclude Server-side admin API behaviour; covered by PHPUnit on EncryptionSuiteController and MigrationService.
- **GIVEN** someone holding user A's session and leaked password started a compromise recovery, committed one record so that abort is refused, and left the migration `in_progress`
- **WHEN** an administrator force-revokes either suite of that migration with `markCompromised: true`
- **THEN** the system MUST revoke the suite without a `409`
- **AND** it MUST revoke the migration's other suite as compromised as well
- **AND** only then it MUST set the migration to `terminated`, releasing the write lock and unlocking the SecretRequests the migration locked

#### Scenario: A request on a revoked suite cannot be filled
@e2e exclude Server-side refusal on a public endpoint; covered by PHPUnit on SecretRequestPolicy and SecretRequestFillController.
- **GIVEN** a SecretRequest whose suite was force-revoked as compromised, with or without an open migration
- **WHEN** someone opens or submits its public fill link
- **THEN** the system MUST refuse with `410` and reason `unavailable`
- **AND** MUST NOT return that suite's certificate

#### Scenario: A failed containment step can be retried
@e2e exclude Server-side failure handling; covered by PHPUnit on EncryptionSuiteController.
- **GIVEN** a compromise force-revoke revoked one end of an in-progress migration, and revoking the other end failed
- **WHEN** the administrator runs the same force-revoke again
- **THEN** the system MUST still find the in-progress migration, revoke the other end, and terminate the migration

#### Scenario: The owner's revoke of a suite mid-migration is refused
@e2e exclude Server-side refusal; covered by PHPUnit on EncryptionSuiteController.
- **GIVEN** user A's suite is part of a migration in state `in_progress`
- **WHEN** A revokes that suite
- **THEN** the system MUST refuse with `409` and `error: migration_in_progress`

#### Scenario: A finished migration does not block revocation
@e2e exclude Server-side check; covered by PHPUnit on MigrationService.
- **GIVEN** every migration the suite was part of is `completed`, `completed_with_errors` or `aborted`
- **WHEN** the suite is revoked
- **THEN** the migration check MUST NOT refuse it

## User Stories

- As a new user, I want Keepiq to set up my encryption automatically when I first enter my master password
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

- [ ] An EncryptionSuite is created automatically for a user on first login to Keepiq
- [ ] RSA key size is at least 4096 bits
- [ ] Private key is stored AES-256 encrypted with the AES-derived key — never in plaintext
- [ ] Master password and AES-derived key never leave the browser — not sent to server or stored in ISession
- [ ] Decrypted private key is imported as WebCrypto CryptoKey with extractable: false
- [ ] CryptoKey is held in a JS variable, never in localStorage or sessionStorage
- [ ] Session timeout is configurable per user (Nextcloud session / 10 min / 30 min), enforced client-side
- [ ] Session expiry clears the in-memory CryptoKey and redirects to the lock screen (full page, not overlay)
- [x] A "Lock vault" button in the app navigation immediately clears keys and redirects to the lock screen
- [ ] Closing all tabs releases JavaScript memory (CryptoKey lost)
- [ ] Unlocking Keepiq on one device does not affect other devices
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

- The AES-derived key and decrypted private key exist only in browser JS memory (WebCrypto CryptoKey). There is no server-side session state for Keepiq's encryption. See ADR-003 for the always-E2E architecture and the DecryptService/EncryptService for internal Nextcloud app access.
- Multiple encryption suites per owner (key rotation beyond compromise recovery) are scoped to a future change.
- CA upload (custom CA chain) is scoped as advanced functionality.
- **Offline root key export** (future): A future version may allow administrators to export the root CA private key to a hardware token or air-gapped device, then purge it from the database. Root operations (intermediate signing) would require the admin to temporarily provide the key. This reduces long-term root key exposure in the database.
- Cross-spec: Secret entity requires `possibly_compromised_at` (datetime) and `migration_error` (text) fields — see secrets spec.
- Cross-spec: SecretRequest entity requires an explicit `encryption_suite_id` FK to know which public certificate to use when encrypting submitted values — see secret-requests spec.
- Related ADRs: ADR-002 (polymorphic ownership), ADR-003 (encryption architecture)
