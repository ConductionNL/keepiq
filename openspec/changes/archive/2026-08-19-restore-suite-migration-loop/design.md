# Design: Restore the suite migration loop

## Context

Compromise recovery is fully specified (`openspec/specs/encryption-suites/spec.md:141`, `:156`) and half built. The server side exists: `MigrationService::initiateCompromiseRecovery` (`lib/Service/MigrationService.php:68`) inserts a `SuiteMigration` row with status `in_progress`, `completeMigration` (`:109`) transitions it to `completed` / `completed_with_errors`, and `MigrationController` (`lib/Controller/MigrationController.php`) exposes `GET /api/v1/migrations/status` and `POST /api/v1/migrations/{id}/complete`. What is missing is everything between those two calls.

Today `src/store/modules/encryptionSuite.js:113-161` calls `initiate`, then `complete` five lines later with `{hasErrors: false}`, then returns `{migration, oldEncryptedPrivateKey, oldPassword, newPrivateKey}` — exactly the material a migration loop needs — and `src/components/CompromiseRecoveryForm.vue:108` throws that return value away and sets `success = true`. Meanwhile `EncryptionSuiteController::compromiseRecovery` (`lib/Controller/EncryptionSuiteController.php:302`) calls `markCompromised` at `:318`, *before* the new suite is even created at `:328`. Every subsequent read of every secret hits `SuiteBlockedException` (`lib/Service/SecretService.php:318`) and 403s. The user is locked out of their entire vault at the exact moment they most need to open it and change every password.

Three further constraints shape the design:

- **ADR-003, non-negotiable.** All crypto is client-side WebCrypto. The master password, the AES-derived key and both private keys stay in browser memory. `importPrivateKey` (`src/crypto/rsa.js:48`) returns a **non-extractable** `CryptoKey`; the server only ever sees ciphertext.
- **There is no per-record migration endpoint.** `appinfo/routes.php` has `migration#getStatus` (`:50`) and `migration#complete` (`:51`) and nothing else. New routes are required.
- **`possibly_compromised_at` has six consumers and zero producers.** `src/health/engine.js:152`, `src/store/modules/health.js:189`, `lib/Service/ComplianceReportService.php:337`, `lib/Listener/SuiteCompromiseListener.php:86`, `lib/Service/RotationPolicyService.php:546`, cleared at `lib/Service/ShareService.php:872`. Nothing sets it, so an entire notification-and-rotation-flag pipeline is dead code. Setting it once turns that pipeline on.

## Goals / Non-Goals

**Goals:**

- Actually migrate the user's ciphertext during compromise recovery, in the browser, resumably, without ever destroying old ciphertext before the new ciphertext is proven readable.
- Cover all six suite-bound stores, not just `doriath_secrets`.
- Keep the vault readable throughout the migration, including after an abandoned session.
- Raise, render and clear `possibly_compromised_at`.
- Tell the user plainly that regaining access is not the same as being safe.

**Non-Goals:**

- Routine key rotation, or multiple suites per owner. Explicitly out of scope at `openspec/specs/encryption-suites/spec.md:387`. Compromise recovery is the only rotation flow and it always supplies the old master password — there is no mode selector to design and none is designed here.
- Routine master-password change (`:128`). It re-wraps the AES blob only, touches no secrets, and already works in `changePassword` (`src/store/modules/encryptionSuite.js:85`).
- Server-side decryption of anything, under any circumstance.
- Changing the link-share cascade-revoke behaviour or the emergency-access envelope-invalidation behaviour. Both are already correct and already implemented.

## Declarative-vs-imperative decision (ADR-031)

**Imperative.** Doriath owns its own database (ADR-001, ADR-004) and does not use OpenRegister for storage, so there is no schema/registry surface to express this declaratively. More fundamentally, the work is an ordered client-side cryptographic pipeline — unwrap old private key → import → decrypt → re-encrypt → verify round-trip → commit — whose steps must run in a specific order, in browser memory, with failure isolation per record. That is imperative control flow by nature. The server side is a small set of REST endpoints on existing controllers, following the existing controller/service/mapper pattern. A declarative expression (config-described migration passes, a generic `{store, id}` record endpoint) was considered and rejected: it would push store identity into a request parameter, which is a direct IDOR footgun for a per-object-authorized write path (see `hydra-gate-no-admin-idor`), and it would hide exactly the ordering guarantees this design exists to enforce.

**Seed data (ADR-001) does not apply.** There are no OpenRegister schemas, registers, or objects in this change, and therefore no seed data to define. No new tables and no `ISchemaWrapper` migration are anticipated either: `possibly_compromised_at` and `migration_error` already exist on `doriath_secrets` (`lib/Migration/Version000008Date20260604000002.php:67`), and outstanding migration work is derived by query rather than stored in new columns (see below).

## Decisions

### Decision 1: Do not mark the old suite compromised until the migration terminates

The controller currently marks the old suite `compromised` first (`EncryptionSuiteController.php:318`). Nothing can be migrated after that, because migration must *read* the old ciphertext and `SecretService` refuses to serve rows on a compromised suite. The order becomes:

1. Generate the new key pair in the browser; POST the public key and the AES-wrapped new private key.
2. Server signs the new certificate and creates the new suite.
3. Server verifies the issued certificate carries the submitted public key (Decision 5). On failure: delete the just-created suite, leave the old suite `active`, create no migration, return a distinct error.
4. Server creates the `SuiteMigration` row (`in_progress`), applies the write lock, locks pending `SecretRequest`s.
5. Browser migrates records (Decisions 2-4).
6. Server verifies no rows remain bound to `old_suite_id`, *then* marks the old suite `compromised`, revokes link shares, invalidates emergency envelopes, unlocks and re-points secret requests, releases the write lock, and transitions the migration to `completed` / `completed_with_errors`.

This matches the existing spec text verbatim ("After all secrets are processed: ... The old EncryptionSuite status MUST be set to `compromised`") — the spec was right and the code diverged.

*Alternative rejected:* keep the early `markCompromised` and special-case the migration read path to bypass `SuiteBlockedException`. Rejected because a deliberate bypass of the suite block is precisely the kind of hole that gets widened by a later well-meaning refactor, and because it leaves an abandoned migration with an unreadable vault.

*Consequence, accepted:* between step 4 and step 6 the old (declared-leaked) key is still `active` and still able to decrypt. This is unavoidable — the migration itself needs it — and is bounded by the write lock plus the resume banner. The user's exposure window is the migration, not the key's lifetime, and the value-rotation warning (Decision 8) is what actually addresses the leak.

### Decision 2: Run the loop in a Web Worker, passing the two `CryptoKey`s by structured clone

Follow the existing pattern: `src/store/modules/health.js:242` creates `new Worker(new URL('../../health/worker.js', import.meta.url))`, posts a payload, listens for `message`, and calls `worker.terminate()` on vault lock (`:285`). The migration worker mirrors that shape — a new `src/migration/worker.js` plus a driver in the encryption-suite store.

The health worker only ever receives already-decrypted plaintext, so it sets no precedent for key transfer. `CryptoKey` **is** structured-cloneable, and cloning preserves `extractable: false` — the raw key material is never exposed to JavaScript in either context. So the driver posts `{oldPrivateKey, newPublicKey}` once at worker start and never again.

*Alternatives rejected:*
- *Re-derive inside the worker from the master password and the wrapped blob.* Rejected: it puts the master password into a second execution context for no benefit.
- *Keep the loop on the main thread.* Rejected: RSA-4096 OAEP over thousands of 446-byte chunks blocks the event loop and freezes the very dialog that is supposed to be showing progress.

*Fallback, required:* `CryptoKey` cloning into a worker has historically been unreliable in some engines. The driver MUST feature-detect by attempting the `postMessage` and, on failure, fall back to running the same pipeline on the main thread in yielding batches. The pipeline module is written once and imported by both paths so the two cannot drift.

### Decision 3: One record per request, four store-specific endpoints, small concurrency window

New routes under the existing migration controller:

```
POST /api/v1/migrations/{id}/secrets/{secretId}
POST /api/v1/migrations/{id}/versions/{versionId}
POST /api/v1/migrations/{id}/attachment-grants/{grantId}
GET  /api/v1/migrations/{id}/work            # remaining ids per store
```

Store-specific paths rather than a generic `{store, id}` body: authorization differs per store (a version is owned through its secret; a grant is owned through its attachment copy) and a store-name request parameter invites an IDOR. Each endpoint enforces the same two-part guard: the row's current `encryption_suite_id` MUST equal the migration's `old_suite_id`, and the row MUST belong to the migration's owner, resolved via `OCP\IUserSession`. That guard is what makes "process only unmigrated rows" a server-side invariant rather than a client promise.

One record per request keeps failure isolation and resumability trivial — there is no partial-batch state to reason about. Latency is handled with a small in-flight window (4 concurrent requests) rather than batching.

`GET .../work` returns the ids still bound to `old_suite_id` per store, paged. This is the derived-progress source: no new counter columns, no drift between a counter and reality, and a resumed migration simply asks again. It also extends the existing spec's own principle ("Migration progress is self-evident from secrets: secrets still pointing to `old_suite_id` have not yet been migrated") to the other stores.

### Decision 4: Verify the round-trip in the browser, commit in one server transaction

Per record, in the worker: decrypt with the old private key → re-encrypt with the new public key → **decrypt the new blob again with the new private key and compare byte-for-byte with the original plaintext** → only then POST. For attachment grants the same shape applies to the wrapped AES file key: unwrap under the old key, re-wrap under the new, unwrap the new wrapper and compare the file-key bytes.

The 2026-07-18 incident is the reason this is not optional: OpenSSL minted a throwaway key pair for a public-key-only CSR and produced ciphertext nobody could decrypt. Encryption succeeding proves nothing about whether anyone can read the result.

Note the worker needs the new **private** key too, not just the public key, in order to verify — so Decision 2's payload is `{oldPrivateKey, newPublicKey, newPrivateKey}`.

Server-side, the new ciphertext write and the `encryption_suite_id` re-point happen in one transaction, together with setting `possibly_compromised_at` and clearing any prior `migration_error`. A record either fully migrates or is untouched; there is no state in which a row points at the new suite while holding old ciphertext.

Plaintext buffers and the per-record plaintext are nulled in the worker's `finally` block, mirroring `src/health/worker.js`.

### Decision 5: Expose the certificate/public-key check as the migration precondition

`CertificateAuthorityService::certCarriesPublicKey` (`lib/Service/CertificateAuthorityService.php:316`) already compares the RSA modulus with `hash_equals`, but it is `private` with two in-file callers inside `signPublicKey()`. Extract it to a public `assertCertCarriesPublicKey(string $certPem, string $publicKeyPem): void` (throwing) plus the existing boolean, and call it in `compromiseRecovery` at step 3 above. Existing in-file callers switch to the extracted method so there is one implementation, not two.

### Decision 6: Failures record `migration_error` on the owning secret; the loop always continues

`migration_error` exists only on `doriath_secrets` (`lib/Db/Secret.php:181`). Rather than add the column to two more tables, a failed version or attachment-grant re-encryption records its error on the **owning secret's** `migration_error`, prefixed with the store it came from. That is where the user will look, it keeps the failure list one flat list, and it needs no schema change.

A failure never aborts the run. At the end, if any record failed, the migration terminates as `completed_with_errors` and the dialog lists the affected secrets with a retry action. Retry re-requests `GET .../work`, which by construction returns exactly the rows that did not make it.

### Decision 7: Extend the write lock into the service layer

`MigrationService::isWriteLocked` (`:199`) exists but has exactly one caller — `EncryptionSuiteController:161`, which only blocks creating a *new suite*. Secret writes, attachment writes and link-share creation are all unguarded. The guard moves into the service layer (`SecretService`, `AttachmentService`, `LinkShareService`, `SecretRequestService` write paths) rather than being sprinkled across controllers, so a future controller cannot forget it. Reads stay open throughout — the migration depends on them and the user needs them.

### Decision 8: Warning copy, and where it lives

`CompromiseRecoveryForm.vue` currently promises "re-encrypt all your secrets" (`:5-7`) and, on completion, "Key rotation complete. Your vault is now secured with a new encryption key." (`:34`). Both are false today and the second would still be misleading once this change lands. Progress and outcome render **inside the recovery dialog** (maintainer decision), as three surfaces:

- **Before confirm** — a warning note card: every stored value must be assumed to have been exposed and must be changed at its source. Rotating the key restores *access* so that the user can go and change those values in an orderly fashion. It does not make the old values safe.
- **During** — a progress indicator driven by the worker (`n of m` across all stores), with the same warning still visible.
- **After** — counts migrated and failed, the failure list with retry, and a repeat of the warning. No wording that implies the vault is now secure.

The flagged-secret warning surface itself hangs off `possibly_compromised_at`, which is plaintext metadata and needs no decryption to render — so it works on the secret row, in the detail view and in the health surface (`src/health/engine.js:152` already computes it) whether or not the vault is unlocked.

### Decision 9: Version-history window is inherited, not re-decided

`openspec/specs/secret-version-history/spec.md:38` already fixes the behaviour: re-encrypt head plus the N most recent versions (default 5), drop older ones, and tell the user. This design implements that as written and adds nothing to it.

## Risks / Trade-offs

- **The compromised key stays `active` for the duration of the migration** → Unavoidable (migration must read with it). Bounded by the write lock, the resume banner, and by the fact that the actual remedy is rotating the values, which the warning copy demands.
- **An abandoned migration leaves the old suite `active` forever and the vault write-locked** → On every unlock, `GET /api/v1/migrations/status` (already implemented) drives a "migration paused — n records remain" banner with a resume action, per the existing spec scenario "Tab closed mid-migration". The vault stays fully *readable* in this state, which is the point of Decision 1.
- **`CryptoKey` structured clone into a worker may fail on some engines** → Feature-detect and fall back to the main-thread yielding path; one shared pipeline module so both paths behave identically.
- **A large vault means a long run** → Per-record requests with a 4-wide window, derived progress, and full resumability. A slow migration is recoverable; a batched one that half-fails is not.
- **Setting `possibly_compromised_at` for the first time activates dormant code** → `SuiteCompromiseListener` will start sending owner notifications for shared secrets and `RotationPolicyService::flagCompromisedSecrets` will start raising `suite_compromise` flags. This is intended, but it means a single recovery can produce a large notification burst; the listener already de-duplicates per owner (`lib/Listener/SuiteCompromiseListener.php`), and this needs an explicit test rather than being discovered in production.
- **Version history is deliberately lossy** → Only head + 5 survive; this is inherited spec behaviour and MUST be stated to the user rather than happening quietly.

## Migration Plan

No database migration is required. Deployment is a normal app release; the change is inert until a user initiates compromise recovery.

Rollback is safe by construction: because old ciphertext is never destroyed until the new blob has been proven readable, and because the old suite stays `active` until the migration terminates, reverting the app mid-migration leaves a vault in which some rows are on the new suite and some on the old — all of them readable, given both master passwords. Re-deploying resumes from `GET .../work`.

One ordering note for deployment: the fake `complete` call in `src/store/modules/encryptionSuite.js:149` must be removed in the same release that adds the real loop. Shipping the loop while leaving the premature `complete` in place would terminate migrations early, which is the current bug wearing a progress bar.

## Open Questions

- **Concurrency window size.** Fixed at 4 in-flight requests as a starting point. Provisional — tune against a large seeded vault; a lower number may be needed on shared instances.
- **Failure-list persistence across sessions.** `migration_error` persists per secret, so the list is reconstructable, but the dialog currently has no route to reach it outside an active recovery. Provisionally, the resume banner is the entry point; a dedicated "secrets that failed migration" view is left to a follow-up if the banner proves insufficient.
