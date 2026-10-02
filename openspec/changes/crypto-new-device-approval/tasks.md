# Tasks: new device approval

## 1. Server

- [x] 1.1 Add the `keepiq_device_approvals` table, entity and mapper with a migration and a `<version>` bump. Verify: a PHPUnit migration test asserts the table and index. Done: `lib/Db/DeviceApproval.php`, `DeviceApprovalMapper.php`, `device_approvals` in Version001000's SCHEMA and `Version001009Date20261002182000` for existing installs (`ConsolidatedSchemaMigrationTest` covers the SCHEMA entry); `<version>` 0.3.4-unstable.20261002182000.
- [x] 1.2 Add `POST /api/v1/device-approvals` (own user only, `#[UserRateLimit(limit: 3, period: 3600)]`, refused when `device_approval_enabled` is false) returning the request id and a one-time request secret, and raising the `device_approval_requested` notification. Verify: PHPUnit for creation, the disabled setting and the notification; a controller attribute test for the rate limit. Done: `DeviceApprovalController::create`; `tests/Unit/Controller/DeviceApprovalControllerTest.php` (creation, switch off, malformed input, rate-limit attribute), `tests/Unit/Notification/DeviceApprovalNotifierTest.php`.
- [x] 1.3 Add `GET /api/v1/device-approvals/pending` and `POST /api/v1/device-approvals/{id}/deny`, both scoped to the request's own user. Verify: PHPUnit refuses another user's request with the same answer as an unknown id. Done: `pending`, `deny`; `DeviceApprovalControllerTest::testAnotherUsersRequestAnswersLikeAnUnknownOne`.
- [x] 1.4 Add `POST /api/v1/device-approvals/{id}/approve` with `#[VaultKeyProofRequired(binds: ['id', 'sealedUnlockKey'], subject: 'active', purpose: 'approve-device')]`, and add it to `VaultKeyProofAttributesTest`. Verify: PHPUnit refuses an approval without a proof, for an expired request, and for another user. Done: `approve` with `#[VaultKeyProofRequired(binds: ['id', 'sealedUnlockKey'], subject: 'active', purpose: 'approve-device')]`, listed in `VaultKeyProofAttributesTest`; expired and foreign requests refused in `DeviceApprovalControllerTest`. A missing proof is refused by `VaultKeyProofMiddleware`, which the attribute test ties to this route.
- [x] 1.5 Add `GET /api/v1/device-approvals/{id}` that needs the request secret, returns the sealed key once and marks the request `consumed`. Verify: PHPUnit asserts a second pickup returns no key and a wrong secret is refused. Done: `show` with the secret in the `X-Keepiq-Request-Secret` header; `testApprovalStoresTheSealedKeyAndPickupReleasesItOnce`.
- [x] 1.6 Add a background job that expires pending requests and audit events for every transition (identifiers only). Verify: PHPUnit for the job and for audit metadata holding no key. Done: `ExpireDeviceApprovalsJob`, audit `device_approval.*`; `testTheJobExpiresAndDropsAnUnclaimedKeyAndAuditsHoldNoKey`.

## 2. Web app

- [x] 2.1 Add the verification phrase helper in `src/crypto/` (shared with account recovery) and the "Approve from another device" path on the lock screen at /lock: one-time key, request, phrase, polling, unlock through `unlockWithRawKey`. Verify: vitest for the phrase and for the unlock after a mocked approval. Done: `src/crypto/verificationPhrase.js`, `src/crypto/deviceApproval.js`, `src/store/modules/deviceApproval.js`, `src/components/DeviceApprovalRequest.vue` on the lock screen; `tests/store/deviceApproval.spec.js`.
- [x] 2.2 Add the approval dialog in `src/dialogs/` for an unlocked user: device details, phrase, warning, master password or passkey confirmation, HPKE seal, approve and deny. Verify: vitest asserts the approve request holds only the sealed key and the proof headers. Done: `src/dialogs/DeviceApprovalDialog.vue`, mounted in `App.vue` while unlocked; `tests/store/deviceApproval.spec.js` asserts the approve body holds only `sealedUnlockKey` plus the proof headers.
- [x] 2.3 Add the `device_approval_enabled` switch to the admin settings. Verify: vitest for the switch; PHPUnit for the default. Done: `src/components/settings/DeviceApprovalSection.vue`; default on in `AdminSettingsService`; `tests/store/deviceApproval.spec.js`.

## 3. Extension

- [x] 3.1 Add "Approve from another device" to the locked popup, sealing and unlocking through the worker's raw-key unlock. Verify: vitest with a mocked API unlocks the worker from an approved request. Done on top of #921's raw-key unlock: `browser-extension/src/lib/deviceApproval.js` holds the one-time key and request secret in worker memory only, the router answers `device-approval-state/start/poll/cancel` (extension pages only), the popup shows the phrase and polls every three seconds. `tests/extension/deviceApproval.spec.js` unlocks the real router from a key sealed with the web app's `sealUnlockKey`, and refuses a key sealed for another request id, a denial, a web-page sender and a disabled feature.

## 4. Officer path

- [ ] 4.1 For users enrolled in organisation account recovery, add "Ask your organisation instead", filing a recovery request with purpose `device` and the device's one-time key, and unlocking the session from the recovered private key without a password reset. Verify: vitest for the purpose flag and the unlock; depends on `crypto-organisation-account-recovery`. **Owed**: depends on `crypto-organisation-account-recovery` (#788), which lane F4 builds next.

## 5. End to end

- [ ] 5.1 Add a Playwright flow with two browser contexts for one user: the second context requests approval, the first approves after the phrases match, and the second context unlocks and lists the vault. Verify: the Playwright spec passes in the E2E job. **Live check owed**: the Playwright flow needs the E2E job; not written in this change.

## Acceptance criteria

- A user can unlock a new browser or the extension by approving it from their unlocked web app, without typing the master password on the new device.
- The server stores and relays only a sealed unlock key it cannot open, and deletes it at first pickup.
- An approval needs the master password or a passkey on the approving device, and a valid vault-key proof.
- Both devices show the same verification phrase; a swapped key shows different phrases.
- Requests expire after 15 minutes, are limited to three per hour, raise a notification and are audited.
- The administrator path exists only for users enrolled in organisation account recovery.
