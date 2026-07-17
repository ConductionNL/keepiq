# Tasks: Passkey Vault Login

## 1. Data layer

- [ ] 1.1 Migration `doriath_passkey_credentials` (`id`, `owner_id`, `credential_id`, `public_key`, `prf_salt`, `wrapped_unlock_key`, `unlock_key_epoch`, `label`, `transports`, `aaguid`, `status` enum `active|stale|revoked`, `last_used_at`, `created_at`); composite index `(owner_id, status)`, unique `(owner_id, credential_id)`
- [ ] 1.2 `PasskeyCredential` entity + `PasskeyMapper` (QBMapper, matching `SecretMapper`/`LinkShareMapper` conventions)
- [ ] 1.3 Add `unlock_key_epoch` to the active EncryptionSuite read path and increment it in the routine master-password-change flow

## 2. Backend service + controller

- [ ] 2.1 `PasskeyService::listForOwner(uid)`, `enroll(uid, dto)`, `revoke(uid, id)` — every method asserts `ownerId === uid` (no-admin-IDOR guard)
- [ ] 2.2 `PasskeyService::loginOptions(uid)` — returns credential ids, `prf_salt`, `wrapped_unlock_key`, `unlock_key_epoch`, and a fresh WebAuthn challenge; refuses stale/revoked envelopes
- [ ] 2.3 `PasskeyService` staleness hooks: mark envelopes `stale` on unlock-key-epoch change; delete all owner envelopes on compromise-recovery suite rotation
- [ ] 2.4 `PasskeyController` (`index`, `challenge`, `create`, `loginOptions`, `destroy`) — all `#[NoAdminRequired]`, owner-scoped; register routes in `appinfo/routes.php` under a commented "Passkey vault login" section
- [ ] 2.5 Reject enrollment when the request cannot present a wrapped envelope (server-side guard mirroring the "vault must be unlocked" rule)

## 3. Frontend crypto

- [ ] 3.1 `src/crypto/passkey.js`: `isPrfSupported()`, `deriveKekFromPrf(prfOutput, credentialId)` (HKDF-SHA256), and envelope wrap/unwrap reusing `src/crypto/envelope.js`
- [ ] 3.2 Enrollment ceremony helper: `create()` → assert `prf.enabled` → `get()` with fresh salt → build envelope from the in-memory vault unlock key
- [ ] 3.3 Unlock ceremony helper: `get()` with stored salt → PRF output → KEK → unwrap unlock key → hand off to the existing `session.unlock` private-key import path

## 4. Frontend UI

- [ ] 4.1 Lock screen: feature-detect + offer "Unlock with passkey" and "Use master password"; on passkey failure fall back to the password field without leaving the screen
- [ ] 4.2 Passkey management settings section: list enrolled passkeys (label, transports, last-used, status), "Add passkey", and per-row "Revoke"
- [ ] 4.3 Enrollment dialog: require unlocked vault, capture a label, run the ceremony, surface a clear message when the authenticator lacks PRF
- [ ] 4.4 Stale-envelope handling in the UI: after a routine master-password change, show a "re-enroll your passkeys" prompt

## 5. Tests

- [ ] 5.1 PHPUnit: `PasskeyService` owner-scoping (IDOR rejection), enroll/list/revoke, `loginOptions` refuses stale/revoked, epoch-change marks stale, rotation deletes all
- [ ] 5.2 JS unit: HKDF KEK derivation is deterministic for a given PRF output+salt; wrap→unwrap round-trip recovers the unlock key; wrong PRF output fails to decrypt
- [ ] 5.3 JS unit: `isPrfSupported()` returns false without `PublicKeyCredential` and the lock screen hides the passkey option
- [ ] 5.4 e2e (Playwright, virtual authenticator): enroll a passkey with an unlocked vault, lock, unlock passwordlessly, revoke, confirm passkey unlock no longer offered

## Acceptance criteria

- Enrollment requires an unlocked vault; the server never receives the master password, the plaintext unlock key, or the raw PRF output.
- Passkey unlock reaches the identical unlocked end-state as a master-password unlock (non-extractable `CryptoKey` in memory).
- The master password is always offered and always works; a lost authenticator causes no data loss.
- Revocation deletes the server-side envelope and removes the passkey unlock option for that authenticator.
- PRF is feature-detected at runtime; unsupported browsers/authenticators see only the master-password path.
- Routine master-password change marks passkey envelopes stale; compromise recovery deletes them all.
- All passkey endpoints are `#[NoAdminRequired]` and reject cross-user credential access.
