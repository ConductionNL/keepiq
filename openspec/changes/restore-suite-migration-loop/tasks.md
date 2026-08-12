# Tasks: Restore the suite migration loop

## 1. Server — precondition and ordering

- [x] 1.1 Extract `CertificateAuthorityService::certCarriesPublicKey` (`lib/Service/CertificateAuthorityService.php:316`) into a public throwing `assertCertCarriesPublicKey(string $certPem, string $publicKeyPem): void`, and repoint the two existing in-file callers in `signPublicKey()` so there is one implementation
- [x] 1.2 Reorder `EncryptionSuiteController::compromiseRecovery` (`lib/Controller/EncryptionSuiteController.php:302`): sign and create the new suite → assert the certificate carries the submitted public key (on failure delete the new suite, leave the old suite `active`, create no `SuiteMigration`, return a distinct error) → create the migration, apply the write lock, lock pending `SecretRequest`s. Remove `markCompromised`, the link-share cascade and passkey deletion from this step
- [x] 1.3 Move terminal work into `MigrationService::completeMigration`: refuse to terminate while any row in any store is still bound to `old_suite_id`; then mark the old suite `compromised`, revoke link shares, delete passkeys, release the write lock, and set `completed` / `completed_with_errors`. Do NOT re-implement `SecretRequest` re-pointing or emergency-envelope invalidation — both are already wired to migration completion via `SuiteMigrationCompletedListener.php:70` (→ `SecretRequestService::unlockAndUpdateSuite`) and `EmergencyAccessSuiteRotationListener.php:77` (→ `invalidateForGrantorRotation`). Verify they still fire once completion is gated on all stores being migrated, since today they fire against an immediately-completed migration

## 2. Server — migration work API

- [x] 2.1 `GET /api/v1/migrations/{id}/work` — paged, per-store lists of ids still bound to `old_suite_id` (secrets, versions within the head+N window, attachment grants owned by the rotating user); derived by query, no new counter columns
- [x] 2.2 Three re-encryption endpoints (`POST /api/v1/migrations/{id}/secrets/{secretId}`, `.../versions/{versionId}`, `.../attachment-grants/{grantId}`), each guarded by "row's current `encryption_suite_id` equals the migration's `old_suite_id` AND row is owned by the migration owner", committing new ciphertext + `encryption_suite_id` re-point + `possibly_compromised_at` + cleared `migration_error` in one transaction; register all four routes in `appinfo/routes.php` with explicit auth attributes
- [x] 2.3 Record per-record failures as store-prefixed `migration_error` on the owning secret (the column exists only on `doriath_secrets`), and drop versions beyond head + N (default 5) as the version pass completes, reporting the drop count to the client

## 3. Server — write lock

- [x] 3.1 Extend `MigrationService::isWriteLocked` (`lib/Service/MigrationService.php:199`) enforcement to the write paths of `AttachmentService`, `LinkShareService` and `SecretRequestService`; `SecretService:1265` already guards secret writes and `EncryptionSuiteController:161` already guards suite creation — extend those two rather than duplicating them. Reads MUST stay open so the migration and the user can both still read

## 4. Server — flag clearing

- [x] 4.1 Ensure `possibly_compromised_at` is cleared only when a secret's `key` value is actually replaced (owner write, or secret-request fulfilment) and propagates to shared copies via the existing sync path (`lib/Service/ShareService.php:872`); confirm renames, folder moves, metadata edits and share operations leave it set
  - Owner and application writes clear it, gated on the existing `$key !== $secret->getKey()` check so a client that echoes the unchanged key back alongside a rename does not clear it. Share propagation already existed at `ShareService:871`. **Secret-request fulfilment has no hook**: `SecretRequestService::fill()` marks the request fulfilled but never writes the blobs into the secret — its docblock says the controller is responsible, and `SecretRequestFillController::fill` does not do it either. So fulfilment does not currently write a value at all; when that path is built it must clear the flag, and the spec scenario stays honest in the meantime.

## 5. Frontend

- [x] 5.1 `src/migration/pipeline.js` — per-record decrypt (old private key) → re-encrypt (new public key) → decrypt-back with the new private key and byte-compare against the original → only then hand off for POST; null plaintext buffers in `finally`; same shape for attachment grants on the wrapped file key
- [x] 5.2 `src/migration/worker.js` following `src/health/worker.js`, plus the driver-side `new Worker(new URL(...), import.meta.url)` wiring mirroring `src/store/modules/health.js:242`; post `{oldPrivateKey, newPublicKey, newPrivateKey}` as structured-cloned non-extractable `CryptoKey`s, feature-detect the clone and fall back to the same pipeline on the main thread in yielding batches; terminate the worker on vault lock
- [x] 5.3 Rewrite `initiateCompromiseRecovery` (`src/store/modules/encryptionSuite.js:113`): delete the premature `POST .../migrations/<migration-id>/complete` at `:149`, unwrap and import the old private key, drive fetch-work → re-encrypt → POST with a 4-wide in-flight window, and only then call `complete` with the real `hasErrors`; expose progress and the failure list as store state
- [x] 5.4 `CompromiseRecoveryForm.vue` — consume the return value that `:108` currently discards; render live progress (`n of m` across all stores), terminal counts, the failure list and a retry action inside the recovery dialog
- [x] 5.5 Replace the warning copy in all three surfaces: before confirm, during, and after. Every stored value must be assumed exposed and changed at its source; rotating the key restores access so the user can go and do that in an orderly fashion. Delete the false "Your vault is now secured with a new encryption key" message (`CompromiseRecoveryForm.vue:34`)
- [ ] 5.6 Resume banner on unlock driven by the existing `GET /api/v1/migrations/status`, showing how many records remain and offering resume (re-enter the old master password), per the existing "Tab closed mid-migration" scenario
- [x] 5.7 Prominent, non-dismissible-while-set `possiblyCompromisedAt` warning on the secret row and detail view, wired to the health surface that already computes it (`src/health/engine.js:152`)

## 6. Tests

- [x] 6.1 Unit (pipeline): a round-trip mismatch leaves the original ciphertext and `encryption_suite_id` untouched, is counted as a failure, and the loop continues; plus a cross-implementation round-trip — re-encrypted in JS/WebCrypto under the new key and decrypted in PHP/OpenSSL, and the reverse — confirming re-chunking at `RSA_CHUNK_SIZE = 446` against the new key
- [x] 6.2 PHPUnit (authorization): each re-encryption endpoint refuses a row not bound to `old_suite_id` and a row owned by another user (nil-UUID `00000000-0000-0000-0000-000000000000` as the foreign owner), and every migration response body carries ciphertext blobs only — never a decrypted value
- [x] 6.3 PHPUnit (lifecycle): completion is refused while any store still has rows on `old_suite_id`; the terminal step performs its work in the specified order; a precondition failure leaves the old suite `active`, creates no migration and re-encrypts nothing
- [x] 6.4 PHPUnit (flag): `possibly_compromised_at` is raised on every migrated row, idempotent on retry, never raised for failed rows, preserved through metadata edits, cleared on value replacement and propagated to shares; `SuiteCompromiseListener` de-duplicates notifications across a large migration
  - Raise half in `MigrationWorkServiceTest` (raised on commit, idempotent on retry, never for a failed row); de-duplication in `SuiteCompromiseListenerTest` (one notification per owner across a 500-secret migration, unflagged rows silent); preserve/clear half in `SecretServiceTest` (metadata edit and unchanged-key re-send both preserve, value replacement clears); share propagation already covered by `ShareServiceTest:515,550`.
- [ ] 6.5 e2e (Playwright): the compromise-recovery flow — the rotate-every-value warning is shown before the user confirms, progress renders inside the dialog, the terminal message reports counts without claiming the vault is secure, and a migrated secret shows the possibly-compromised warning afterwards

## Acceptance criteria

- A user who declares their master password compromised can read every secret in their vault afterwards, and no read returns `SuiteBlockedException` mid-migration.
- No row in any of the six suite-bound stores is left pointing at the old suite when the migration reports `completed`.
- No old ciphertext is destroyed anywhere before the corresponding new ciphertext has been decrypted back and byte-compared.
- Closing the tab mid-migration loses no progress: reopening reports the remaining count and resumes on exactly the unmigrated rows.
- A single failing record produces a `migration_error` and a listed failure, and does not stop the run.
- Every migrated secret carries `possibly_compromised_at`, and `lib/Service/ComplianceReportService.php:337` reports a non-zero count for the first time.
- The recovery UI never tells the user the vault is safe; it tells them to change every value at its source.
- Compromise recovery with a certificate that does not carry the submitted public key aborts with the vault untouched.

## Quality reminders

- ADR-003 is absolute: master password, AES-derived key and both private keys stay in browser memory. If any task tempts a server-side decrypt, the task is wrong.
- Commits use Conventional Commits with an `Assisted-by:` trailer; the human contributor adds `Signed-off-by` — the agent never does.
- New PHP files need the SPDX header; changed public/protected methods need `@spec` tags (`hydra-gate-spec-coverage`); new endpoints need contract tests (`hydra-gate-contract-coverage`).
- New routes need explicit auth attributes and per-object guards (`hydra-gate-route-auth`, `hydra-gate-no-admin-idor`) — a migration endpoint that trusts the record id is an IDOR.
- Example ids in tests and docs stay obvious placeholders (`<migration-id>`, `YOUR_API_KEY_HERE`, nil UUID). Never realistic entropy.
- This change is large; if it grows past the tasks listed here, split it rather than widening the PR.
