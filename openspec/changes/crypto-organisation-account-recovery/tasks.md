# Tasks: organisation account recovery

## 1. Data and configuration

- [x] 1.1 Add the five recovery tables with entities and mappers, a migration step and a `<version>` bump. Verify: a PHPUnit migration test asserts each table and the unique approval index. Done: `lib/Db/Recovery*.php`, the five tables in Version001000's SCHEMA (`ConsolidatedSchemaMigrationTest`) and `Version001010Date20261002183000` for existing installs; unique `keepiq_ra_request_officer_uniq`; `<version>` 0.3.4-unstable.20261002183000.
- [x] 1.2 Add `account_recovery_policy` (default `off`), officer list and threshold handling to the admin settings service, refusing a threshold above the officer count and officers without an active suite. Verify: PHPUnit for each accept and reject path. Done: `RecoveryPolicyService`, `RecoveryAdminController` (`#[AuthorizedAdminSetting]`, `#[PasswordConfirmationRequired]`); `AccountRecoveryTest::testTheAdministratorSettingsAreChecked`, `RecoveryAdminAttributesTest`.

## 2. Recovery key

- [x] 2.1 Add the officer endpoint that stores a recovery certificate issued through `CertificateIssuanceService::signPublicKey()` and one wrapped copy per officer, refusing a copy for a non-officer. Verify: PHPUnit asserts the stored copies and that no endpoint returns another officer's copy. Done: `RecoveryKeyService::createKey`, `RecoveryOfficerController::createKey`/`ownCopy`; `testOnlyOfficersCreateTheKeyAndEachGetsOnlyTheirOwnCopy`.
- [x] 2.2 Add the officer page action that generates the key pair in the browser, wraps it for every officer, posts, and discards the key. Verify: vitest asserts the request body holds only wrapped copies and a public key. Done: `src/crypto/accountRecovery.js` `createRecoveryKey`, `RecoveryOfficerPanel.vue`; `tests/store/accountRecovery.spec.js`.
- [x] 2.3 Add officer add and remove, and recovery key rotation and retirement (D7). Verify: PHPUnit for removal deleting the copy and for a retired key refusing new enrolments. Done: officer removal deletes the copy (`RecoveryPolicyService::update`), a new key retires the old one, `retire`; `testARetiredKeyTakesNoNewEnrolments`, `testANewKeyRetiresTheOldOneAndOldEnrolmentsAreNoLongerCurrent`.

## 3. Enrolment

- [x] 3.1 Add the user enrolment endpoints (read, write, withdraw; withdraw refused under `required`). Verify: PHPUnit for each policy value. Done: `RecoveryUserController` enrolment routes; `testWithdrawalIsRefusedUnderTheRequiredPolicy`.
- [x] 3.2 Add enrolment in the user settings and at unlock under `required`, showing the certificate fingerprint and building the envelope with `buildRecoveryEnvelope`. Verify: vitest asserts the posted envelope opens with the recovery private key in a test and that no request carries the private key PEM. Done: `AccountRecoveryEnrolment.vue` in Settings, Security, and `LockScreen::enrolForRecovery` at unlock; the certificate is checked against the instance CA chain (`src/certificates/x509.js` `chainsTo`) and its fingerprint shown; `tests/store/accountRecovery.spec.js`.

## 4. Requests and approvals

- [x] 4.1 Add request creation from the lock screen at /lock, the X25519 request key in IndexedDB, the 72 hour expiry, and the verification phrase. Verify: vitest for the phrase derivation and the stored key; PHPUnit for expiry. Done: `ForgotPasswordRecovery.vue` on the lock screen, the non-extractable X25519 key in IndexedDB (`requestKeyStore`), 72 hours, the phrase; vitest and `testADeclineEndsTheRequestAndExpiryDropsTheSealedResult`.
- [x] 4.2 Add approve (with the `approve-account-recovery` vault-key proof, listed in `VaultKeyProofAttributesTest`) and decline, refusing self-approval and counting distinct officers. Verify: PHPUnit for the proof requirement, self-approval, duplicate approvals and the threshold. Done: `RecoveryOfficerController::approve` with `#[VaultKeyProofRequired(binds: ['id'], subject: 'active', purpose: 'approve-account-recovery')]` (in `VaultKeyProofAttributesTest`), `decline`; `testTheThresholdCountsDistinctOfficersAndRefusesSelfApproval`.
- [x] 4.3 Release handoff material only to an approving officer after the threshold is met, accept the sealed result, and release it only to the requesting user. Verify: PHPUnit for every wrong-state and wrong-caller refusal, answered identically. Done: `handoff`, `postSealed`, `myRequest`; `testHandoffMaterialReachesOnlyItsOwnersAndIsDeletedOnCompletion`.
- [x] 4.4 Add the officer approval dialog with the phrase and the handoff in the browser (D4). Verify: vitest asserts the sealed result opens with the request key and that the officer page keeps no key after posting. Done: `RecoveryOfficerPanel.vue`, `sealHandoff`; vitest opens the sealed result with the request key only.
- [x] 4.5 Add completion: open the sealed result, set a new master password, call `PUT /api/v1/suites/{id}/private-key` with a proof by the recovered key, and offer a key rotation. Verify: vitest for the completion calls; PHPUnit asserts the sealed result is deleted on completion. Done: `useAccountRecoveryStore().complete`, the rotation offer after recovery on the lock screen; vitest for the calls, PHPUnit for the deleted sealed result.

## 5. Lifecycle, audit and notifications

- [x] 5.1 Add listeners that remove old-suite enrolments after a migration and a revoked suite's enrolment and open requests, and the re-enrolment step in the rotation flow. Verify: PHPUnit for both listeners; vitest for the rotation step. Done: `RecoverySuiteListener` on both events; `testEnrolmentsAndRequestsFollowTheSuite`. The re-enrolment after a rotation runs at the next unlock (`enrolAtUnlock` re-enrols whenever the enrolment no longer matches the active key and suite) rather than inside the rotation dialog; vitest covers it.
- [x] 5.2 Add audit events for key, officer, enrolment, request, approval and completion (identifiers only) and notification subjects for new requests and outcomes. Verify: PHPUnit asserts no audit metadata holds an envelope or key. Done: `RecoveryAudit` with whitelisted identifiers, four notification subjects; the handoff test asserts no audit metadata holds an envelope, a copy or a sealed result.
- [x] 5.3 Add the enrolled-user warning to `AdminSuiteSection.vue`. Verify: vitest renders the warning for an enrolled user's suite. Done: `AdminSuiteSection.vue` warning, backed by `GET /api/v1/recovery/admin/enrolled`; vitest.

## 6. End to end

- [ ] 6.1 Add a Playwright flow: a user enrols, forgets their password, files a request, two officers approve after comparing the phrase, and the user sets a new master password and reads their old secrets. Verify: the Playwright spec passes in the E2E job. **Live check owed**: the Playwright flow needs the E2E job; not written in this change.

## Acceptance criteria

- An enrolled user who forgot their master password regains their vault with the same key pair and every secret readable.
- The server stores only certificates, public keys and ciphertext; no stored value opens without a key the server does not have.
- A recovery needs the configured number of distinct officer approvals, each proven with the officer's own vault key, and an officer cannot approve their own recovery.
- The recovered private key reaches only the browser that filed the request, sealed to that request's key.
- The user sees the verification phrase, is told who handled the recovery, and is offered a key rotation.
- Enrolments and officer copies survive routine password changes and are rebuilt or removed on rotation and revocation.
