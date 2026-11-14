# Tasks: regain access with a recovery kit when the master password is lost

## 1. Backend

- [ ] 1.1 Store one wrapped recovery copy per user and suite (`lib/Db/` entity and mapper, migration), with routes to create, fetch for the signed-in user only, and delete it. Verify: PHPUnit for create, replace, fetch by another user (refused) and delete.
- [ ] 1.2 Rate limit the fetch and write `recovery_kit.created`, `recovery_kit.used` and `recovery_kit.revoked` to the audit trail. Verify: PHPUnit for the limit and the three events.

## 2. Web app

- [ ] 2.1 `src/crypto/recoveryKit.js`: generate the recovery code, derive the key with Argon2id (`src/crypto/argon2.js`), wrap and unwrap the suite private key. Verify: vitest round trip, a wrong code fails, and no request body carries the code or the unwrapped key.
- [ ] 2.2 Personal settings and the export screen (board `KqExporteren`): create, print and download the kit, and show when the current kit was made. Verify: vitest for the dialog, Playwright creates a kit and downloads it.
- [ ] 2.3 `src/views/LockScreen.vue`: "I lost my master password" takes the recovery code, unwraps the key, sets a new master password through the routine change, then deletes the spent copy and offers a new kit. Verify: Playwright recovers with a kit, then the old code is refused.

## 3. Specs and matrix

- [ ] 3.1 Set `crypto-12` to `built` with the files above as evidence, and archive this change.
