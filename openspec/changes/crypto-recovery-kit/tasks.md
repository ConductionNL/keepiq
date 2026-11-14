# Tasks: regain access with a recovery kit when the master password is lost

## 1. Backend

- [x] 1.1 Store one wrapped recovery copy per user and suite (`lib/Db/` entity and mapper, migration), with routes to create, fetch for the signed-in user only, and delete it. Verify: PHPUnit for create, replace, fetch by another user (refused) and delete.
  - `keepiq_recovery_kits` (declared in the consolidated schema for a fresh install, created by `Version001020Date20261114120000` on an existing one, backed up with the vault), `RecoveryKit`/`RecoveryKitMapper`, `RecoveryKitService` and `RecoveryKitController`: `GET/PUT/DELETE /api/v1/recovery-kit`, `GET /api/v1/recovery-kit/copy`, `POST /api/v1/recovery-kit/spent`. Every route acts on the session user's own active suite, chosen on the server; no route takes a user id. Account deletion removes the kit. PHPUnit `tests/Unit/Service/RecoveryKitServiceTest.php`.
- [x] 1.2 Rate limit the fetch and write `recovery_kit.created`, `recovery_kit.used` and `recovery_kit.revoked` to the audit trail. Verify: PHPUnit for the limit and the three events.
  - The copy's fetch carries `#[UserRateLimit(limit: 5, period: 3600)]`, so every recovery attempt counts. `recovery_kit.created`, `recovery_kit.used` and `recovery_kit.revoked` go to the audit trail with the suite as the object and no metadata. Same test file.

## 2. Web app

- [x] 2.1 `src/crypto/recoveryKit.js`: generate the recovery code, derive the key with Argon2id (`src/crypto/argon2.js`), wrap and unwrap the suite private key. Verify: vitest round trip, a wrong code fails, and no request body carries the code or the unwrapped key.
  - A 160-bit code in Crockford base32 (read back whatever the case, spacing or look-alike letters), wrapped with `encryptSnapshot()`; the stored `kdf` names the version, the Argon2id parameters and the salt. The requests are in `src/services/recoveryKit.js`. `tests/vitest/recoveryKit.spec.js` covers the round trip, a wrong, malformed or unreadable code, and that no request URL, body or header carries the code, the unwrapped key or the new master password.
- [x] 2.2 Personal settings and the export screen (board `KqExporteren`): create, print and download the kit, and show when the current kit was made. Verify: vitest for the dialog, Playwright creates a kit and downloads it.
  - `src/components/RecoveryKitPanel.vue`, in Settings, Security and beside the encrypted backup in the export dialog: when the kit was made, making one with the master password, the code shown once with Download (text file) and Print. Vitest in `tests/components/RecoveryKitPanel.spec.js`. The Playwright run is not done in this lane.
- [x] 2.3 `src/views/LockScreen.vue`: "I lost my master password" takes the recovery code, unwraps the key, sets a new master password through the routine change, then deletes the spent copy and offers a new kit. Verify: Playwright recovers with a kit, then the old code is refused.
  - `src/components/RecoveryKitUnlock.vue`, shown on the lock screen to a user with a kit. The new envelope goes through the routine `PUT /suites/{id}/private-key`, its key proof signed with the unwrapped key (`provePrivateKey()` in `reauth.js`, which `proveMasterPassword()` now calls); then the kit is spent, the session unlocks with the new password and a new kit is offered before continuing. Vitest in the same spec file; the Playwright run is not done in this lane.

## 3. Specs and matrix

- [ ] 3.1 Set `crypto-12` to `built` with the files above as evidence, and archive this change.
