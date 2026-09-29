# Tasks: new device approval

## 1. Server

- [ ] 1.1 Add the `keepiq_device_approvals` table, entity and mapper with a migration and a `<version>` bump. Verify: a PHPUnit migration test asserts the table and index.
- [ ] 1.2 Add `POST /api/v1/device-approvals` (own user only, `#[UserRateLimit(limit: 3, period: 3600)]`, refused when `device_approval_enabled` is false) returning the request id and a one-time request secret, and raising the `device_approval_requested` notification. Verify: PHPUnit for creation, the disabled setting and the notification; a controller attribute test for the rate limit.
- [ ] 1.3 Add `GET /api/v1/device-approvals/pending` and `POST /api/v1/device-approvals/{id}/deny`, both scoped to the request's own user. Verify: PHPUnit refuses another user's request with the same answer as an unknown id.
- [ ] 1.4 Add `POST /api/v1/device-approvals/{id}/approve` with `#[VaultKeyProofRequired(binds: ['id', 'sealedUnlockKey'], subject: 'active', purpose: 'approve-device')]`, and add it to `VaultKeyProofAttributesTest`. Verify: PHPUnit refuses an approval without a proof, for an expired request, and for another user.
- [ ] 1.5 Add `GET /api/v1/device-approvals/{id}` that needs the request secret, returns the sealed key once and marks the request `consumed`. Verify: PHPUnit asserts a second pickup returns no key and a wrong secret is refused.
- [ ] 1.6 Add a background job that expires pending requests and audit events for every transition (identifiers only). Verify: PHPUnit for the job and for audit metadata holding no key.

## 2. Web app

- [ ] 2.1 Add the verification phrase helper in `src/crypto/` (shared with account recovery) and the "Approve from another device" path on the lock screen at /lock: one-time key, request, phrase, polling, unlock through `unlockWithRawKey`. Verify: vitest for the phrase and for the unlock after a mocked approval.
- [ ] 2.2 Add the approval dialog in `src/dialogs/` for an unlocked user: device details, phrase, warning, master password or passkey confirmation, HPKE seal, approve and deny. Verify: vitest asserts the approve request holds only the sealed key and the proof headers.
- [ ] 2.3 Add the `device_approval_enabled` switch to the admin settings. Verify: vitest for the switch; PHPUnit for the default.

## 3. Extension

- [ ] 3.1 Add "Approve from another device" to the locked popup, sealing and unlocking through the worker's raw-key unlock. Verify: vitest with a mocked API unlocks the worker from an approved request.

## 4. Officer path

- [ ] 4.1 For users enrolled in organisation account recovery, add "Ask your organisation instead", filing a recovery request with purpose `device` and the device's one-time key, and unlocking the session from the recovered private key without a password reset. Verify: vitest for the purpose flag and the unlock; depends on `crypto-organisation-account-recovery`.

## 5. End to end

- [ ] 5.1 Add a Playwright flow with two browser contexts for one user: the second context requests approval, the first approves after the phrases match, and the second context unlocks and lists the vault. Verify: the Playwright spec passes in the E2E job.

## Acceptance criteria

- A user can unlock a new browser or the extension by approving it from their unlocked web app, without typing the master password on the new device.
- The server stores and relays only a sealed unlock key it cannot open, and deletes it at first pickup.
- An approval needs the master password or a passkey on the approving device, and a valid vault-key proof.
- Both devices show the same verification phrase; a swapped key shows different phrases.
- Requests expire after 15 minutes, are limited to three per hour, raise a notification and are audited.
- The administrator path exists only for users enrolled in organisation account recovery.
