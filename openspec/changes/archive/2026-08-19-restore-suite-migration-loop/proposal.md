---
kind: code
---

# Proposal: Restore the suite migration loop in compromise recovery

## Why

Compromise recovery is already specified in full (`openspec/specs/encryption-suites/spec.md:141` "Master Password Change — Compromise Recovery" and `:156` "Suite Migration") and is **MVP** tier in the roadmap (`docs/FEATURES.md:100` "Compromise recovery (full key rotation + migration)", `:101` "Suite migration with per-secret error tracking"). The code never implemented the migration half. Verified today:

- `src/store/modules/encryptionSuite.js` `initiateCompromiseRecovery()` generates the new key pair, POSTs `/api/v1/suites/compromise-recovery`, and then **immediately** POSTs `/api/v1/migrations/{id}/complete` with `{hasErrors: false}` having migrated nothing. Its own comment claims "the browser will decrypt/re-encrypt each one" — nothing does.
- It returns `{migration, oldEncryptedPrivateKey, oldPassword, newPrivateKey}` — everything a migration loop would need — and `src/components/CompromiseRecoveryForm.vue:108` **discards the return value**.
- Server-side `lib/Controller/EncryptionSuiteController.php::compromiseRecovery()` marks the old suite `compromised` **before** any migration runs, cascade-deletes link shares, and deletes passkeys.

Net effect for a user who declares their master password leaked: every secret still points at the now-compromised old suite, every read throws `SuiteBlockedException` → 403 → *"This secret is locked because its encryption suite was revoked."* The user loses their entire vault at exactly the moment they most need to get into it. The spec's own acceptance checklist item *"Compromise recovery generates a new RSA key pair and migrates all secrets"* is still unticked.

The damage is not limited to reads. `possibly_compromised_at` is consumed in four places (`src/health/engine.js:152`, `src/store/modules/health.js:189`, `lib/Service/ComplianceReportService.php:337`, and cleared at `lib/Service/ShareService.php:872`) and drives two more downstream behaviours (`lib/Listener/SuiteCompromiseListener.php:86` owner notification, `lib/Service/RotationPolicyService.php:546` auto rotation flags) — but **nothing in the codebase ever sets it**. That whole chain is dead code today.

## What Changes

**Implement what is already specified** (`encryption-suites` §Suite Migration — no re-specification, only implementation):

- The in-browser per-secret migration loop: fetch blob → decrypt with the old private key → re-encrypt under the new public key → POST → server re-points `encryption_suite_id`.
- The account write lock for the duration of the migration, and locking of pending `SecretRequest`s.
- Server-persisted, resumable migration state so a closed tab does not lose progress; on resume only rows still pointing at `old_suite_id` are processed.
- Per-row `migration_error` with continue-on-failure, and a failure list surfaced to the user.

**Newly specified behaviour** (the small, additive spec delta):

- **All six suite-bound stores are covered, with an explicit disposition each.** The spec's "all secrets" language covers `oc_doriath_secrets` only; a loop over that table alone silently strands the other five. Verified dispositions:
  - `oc_doriath_secrets` (`key`, `login`, `additional_fields`) — **re-encrypt**. Not implemented.
  - `oc_doriath_secret_versions` (own `encryption_suite_id`, `lib/Db/SecretVersion.php:114`) — **re-encrypt the bounded window** already fixed by `secret-version-history` (head + N most recent, default 5; older dropped). Not implemented.
  - `oc_doriath_attachment_grants` (`wrapped_file_key`, `lib/Db/AttachmentGrant.php:89`) — **re-wrap the owner's own grants**. Not implemented, and the blob is unreadable forever if this is skipped.
  - `oc_doriath_secret_requests` — carries no ciphertext of its own; the suite FK binds it. **Lock, then re-point.** `SecretRequestMapper::unlockAndUpdateSuite` exists but is never called from the recovery path.
  - `oc_doriath_link_shares` (`encrypted_secret_snapshot`) — **revoke**, unchanged (`implement-link-sharing#5.2`); already implemented via `LinkShareService::deleteByUserId`.
  - `oc_doriath_emergency_contacts` (`recovery_envelope`) — **invalidate**, unchanged. The single envelope is wrapped to the *grantee's* key and escrows the grantor's *old* private key as plaintext, so the rotating user cannot re-wrap it alone; already implemented and already required by `emergency-access` §Envelope Invalidation on Key Change.
- **`possibly_compromised_at` behavioural lifecycle.** The data model is already fixed at `openspec/specs/secrets/spec.md:70`; what is missing is the behaviour — the flag is actually **raised** on every migrated row, **rendered** as a hard-to-ignore warning, and **cleared** when the secret's value is replaced.
- **User-directed warning language.** When a user declares their master password compromised, the UI MUST tell them plainly that they should rotate **every secret value**, and that restoring access to the migrated secrets exists *only* so they can visit those sites and set new passwords in an orderly fashion. Regained access is **not** an all-clear.
- **Verify-before-overwrite.** Re-encrypted ciphertext MUST be proven to decrypt back to the original plaintext before the original is overwritten or discarded. Old ciphertext is never destroyed before the new ciphertext is proven readable.
- **Precondition on the new suite.** Migration MUST refuse to start unless the new suite's signed certificate carries the public key that was submitted (`certCarriesPublicKey`, `lib/Service/CertificateAuthorityService.php`). This is a real incident, not a hypothetical: on 2026-07-18 OpenSSL minted a throwaway key pair for a public-key-only CSR, producing ciphertext nobody could decrypt.
- **Ordering fix.** The old suite MUST NOT be marked `compromised` before the migration completes — doing so is what blocks reads mid-flight today.

Not in this change: routine key rotation / multiple suites per owner, explicitly scoped out at `openspec/specs/encryption-suites/spec.md:387`. Compromise recovery remains the **only** rotation flow, and it always supplies the old master password. Routine master-password change (`encryption-suites:128`) is unaffected — it re-wraps the AES blob only, touches no secrets, and already works.

## Capabilities

### New Capabilities

None. This change implements an existing requirement and adds a small additive delta to two existing specs.

### Modified Capabilities

- `encryption-suites`: migration MUST cover all six suite-bound ciphertext stores with a stated disposition each; MUST verify re-encrypted ciphertext round-trips before discarding the original; MUST refuse to start unless the new suite's certificate carries the submitted public key; MUST NOT mark the old suite `compromised` until migration terminates; MUST present the "rotate every value — this is not an all-clear" warning.
- `secrets`: the behavioural lifecycle of `possibly_compromised_at` (raised on every migrated row, rendered as a prominent warning, cleared when the value is replaced), anchored to the existing data-model entry at `openspec/specs/secrets/spec.md:70`.

## Impact

**Frontend** — `src/store/modules/encryptionSuite.js` (real migration driver replacing the fake `complete` call), `src/components/CompromiseRecoveryForm.vue` (consume the return value; render progress and the warning inside the recovery dialog), a new migration web worker following the existing `src/health/worker.js` pattern, `src/crypto/rsa.js` (re-chunk against the new key: `RSA_CHUNK_SIZE = 446`, wire format `[4-byte BE chunk count][512-byte OAEP-SHA256 blocks]`, RSA-4096 — mechanical), health/warning surfaces that already read `possiblyCompromisedAt`.

**Backend** — `lib/Controller/EncryptionSuiteController.php::compromiseRecovery()` (ordering at `:318` `markCompromised` before anything is migrated; precondition check), `lib/Controller/MigrationController.php` + `lib/Service/MigrationService.php` (resumable state, per-store progress, terminal transition to `completed` / `completed_with_errors`). New re-encryption routes are required — `appinfo/routes.php` has **no** per-record migration endpoint today, only `migration#getStatus` and `migration#complete`. `MigrationService::isWriteLocked` exists but has exactly one caller (`EncryptionSuiteController:161`, blocking new-suite creation only), so the lock must be extended to the secret/attachment/share write paths. `SecretRequestMapper::unlockAndUpdateSuite` (`:188`) already implements the request re-point and is simply never called from the recovery path. `CertificateAuthorityService::certCarriesPublicKey` (`:316`) is currently `private` with two in-file callers and needs to be exposed for the precondition check.

**Data** — no new tables and no schema change is anticipated; `possibly_compromised_at` and `migration_error` already exist on `doriath_secrets` (`lib/Migration/Version000008Date20260604000002.php:67`). Any per-store progress counters needed for resumability are recorded on the existing `doriath_suite_migrations` row.

**Downstream** — setting `possibly_compromised_at` for the first time activates `lib/Listener/SuiteCompromiseListener.php` (owner notification for shared secrets) and `lib/Service/RotationPolicyService.php::flagCompromisedSecrets` (auto `suite_compromise` rotation flags), both currently unreachable. `lib/Service/ComplianceReportService.php:337` starts reporting non-zero.

**OpenConnector** — an application vault is not a user vault and has no master password, so this flow does not apply to application-owned suites; connector credentials stored in Doriath are unaffected by a user's compromise recovery.

**Security** — this change touches encryption directly. It reduces exposure (secrets stop living under a key the user has declared leaked) but the crypto invariants of ADR-003 are non-negotiable: the master password, the AES-derived key and both private keys stay in browser memory; only ciphertext crosses the wire; the server never sees plaintext at any step of the migration.
