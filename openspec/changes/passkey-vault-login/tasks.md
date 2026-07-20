# Tasks: Passkey Vault Login

## 1. Data layer

- [x] 1.1 Migration `doriath_passkey_credentials` (`id`, `owner_id`, `credential_id`, `public_key`, `prf_salt`, `wrapped_unlock_key`, `unlock_key_epoch`, `label`, `transports`, `aaguid`, `status`, `last_used_at`, `created_at`); index `(owner_id, status)` + `(owner_id)`. Note: per-owner credential-id uniqueness is enforced in the mapper (`findByCredentialId`) rather than a unique index over the TEXT column (not portable across DB engines) — `Version000031Date20260720060000`
- [x] 1.2 `PasskeyCredential` entity + `PasskeyMapper` (QBMapper); `findById`, `findByOwner`, `findActiveByOwner`, `findByCredentialId`, `deleteByOwner`, `markOwnerStale`. The management `jsonSerialize` never exposes the wrapped envelope or PRF salt
- [x] 1.3 `unlock_key_epoch` column added to `doriath_enc_suites` (same migration) + `EncryptionSuite` entity; incremented in the routine master-password-change flow

## 2. Backend service + controller

- [x] 2.1 `PasskeyService::listForOwner/enroll/revoke` — every method loads by id AND asserts `ownerId === uid` (no-admin-IDOR)
- [x] 2.2 `PasskeyService::loginOptions(uid)` — active credentials' `credentialId`/`prfSalt`/`wrappedUnlockKey`/epoch + a fresh challenge; refuses (and marks stale) any envelope whose epoch trails the suite
- [x] 2.3 Staleness hooks: `markStaleOnPasswordChange` (routine change) and `deleteAllOnRotation` (compromise recovery), wired from `EncryptionSuiteController::updatePrivateKey`/`compromiseRecovery`
- [x] 2.4 `PasskeyController` (`index`, `challenge`, `create`, `loginOptions`, `used`, `destroy`) — all `#[NoAdminRequired]`, owner-scoped; routes under a commented "Passkey vault login" section
- [x] 2.5 Enrollment rejects a request missing `credentialId`/`prfSalt`/`wrappedUnlockKey` (the server-side "vault must be unlocked" mirror — no envelope, no enrollment) + rejects duplicate credential ids

## 3. Frontend crypto

- [x] 3.1 `src/crypto/passkey.js`: `isPrfSupported()`, `deriveKekFromPrf(prfOutput, credentialId)` (HKDF-SHA256, credential-id salt, `doriath-passkey-kek-v1` info), `wrapUnlockKey`/`unwrapUnlockKey` (AES-256-GCM, IV-framed base64), base64url helpers; `src/crypto/aes.js` gains `deriveUnlockKeyRaw` (extractable raw unlock key from master password + envelope salt) and `decryptPrivateKeyWithRawKey`
- [x] 3.2 Enrollment ceremony (`passkey` store `enroll`): `create()` → assert `prf.enabled` (else abort + discard) → `get()` with a fresh 32-byte salt → build the envelope from the master-password-derived raw unlock key
- [x] 3.3 Unlock ceremony (`unlockWithPasskey`): `login-options` → `get()` with the stored salt → PRF output → KEK → unwrap the raw unlock key → `session.unlockWithRawKey` reaches the identical non-extractable-CryptoKey end-state as a master-password unlock

## 4. Frontend UI

- [x] 4.1 Lock screen: feature-detect + offer "Unlock with passkey" above the master-password field (probed via `login-options`); on passkey failure the error shows and the master-password field stays available — no navigation away
- [x] 4.2 `PasskeyManager` (user-settings Security section): lists enrolled passkeys (label, status, last-used), "Add passkey", per-row "Revoke"; hidden entirely where WebAuthn is absent
- [x] 4.3 Enrollment form requires the unlocked vault + a master-password re-entry (to derive the raw unlock key for wrapping); a PRF-less authenticator surfaces a clear "does not support passkey unlock" message
- [x] 4.4 Stale-envelope handling: a warning note prompts re-enrollment when any credential is `stale` (set by the epoch check after a master-password change)

## 5. Tests

- [x] 5.1 PHPUnit `PasskeyServiceTest` (8): owner-scoping IDOR rejection on revoke; enroll binds the current epoch + rejects missing-envelope/duplicate; `loginOptions` refuses + marks stale a trailing-epoch credential; password-change→markStale and rotation→deleteAll hooks; missing credential throws
- [x] 5.2 JS unit `passkey-prf.spec.js` (7): HKDF KEK wrap→unwrap round-trips; a wrong PRF output and a wrong credential-id salt both fail to unwrap; fresh IV per wrap; **end-to-end** — the PRF-recovered raw key decrypts the same private-key blob the master password produces; base64url round-trip
- [x] 5.3 `isPrfSupported()` returns false with no `PublicKeyCredential` (covered in `passkey-prf.spec.js`); the lock screen only offers the option when supported AND enrolled
- [x] 5.4 e2e: covered by deploy-time live verification (management endpoints owner-scoped, enrollment guard, epoch staleness, feature detection). The full WebAuthn PRF ceremony needs a PRF-capable virtual authenticator; verified as far as the ceremony boundary and via the end-to-end crypto unit test — no committed Playwright spec

## Acceptance criteria

- Enrollment requires an unlocked vault; the server never receives the master password, the plaintext unlock key, or the raw PRF output.
- Passkey unlock reaches the identical unlocked end-state as a master-password unlock (non-extractable `CryptoKey` in memory).
- The master password is always offered and always works; a lost authenticator causes no data loss.
- Revocation deletes the server-side envelope and removes the passkey unlock option for that authenticator.
- PRF is feature-detected at runtime; unsupported browsers/authenticators see only the master-password path.
- Routine master-password change marks passkey envelopes stale; compromise recovery deletes them all.
- All passkey endpoints are `#[NoAdminRequired]` and reject cross-user credential access.
