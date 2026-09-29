# Design: organisation account recovery

## Context

Code at development `4c214a9d`:

- Key hierarchy (ADR-003, `src/crypto/aes.js`): master password plus the suite envelope's salt gives the raw unlock key (`deriveUnlockKeyRaw`, `:104`), which decrypts the AES-wrapped RSA-4096 suite private key (`decryptPrivateKey` `:79`, `decryptPrivateKeyWithRawKey` `:132`). The browser holds the private key as a non-extractable `CryptoKey`.
- Emergency access escrows the grantor's private key PEM with a hybrid envelope to a grantee certificate: `buildRecoveryEnvelope(privateKeyPem, granteeCertificatePem)` (`src/crypto/emergencyEnvelope.js:52`, AES-256-GCM content key RSA-OAEP wrapped) and `openRecoveryEnvelope()` (`:93`). The server stores only the envelope (`lib/Service/EmergencyAccessService.php`, `designate` `:161`, `fetchEnvelope` `:370` releasing it only to the named grantee in the `approved` state). On rotation the rotating browser re-envelopes contacts (`lib/Controller/MigrationController.php:514`, route `appinfo/routes.php:68`), and `lib/Listener/EmergencyAccessSuiteRotationListener.php:44` sweeps the rest.
- Replacing a suite's private-key wrapping: `PUT /api/v1/suites/{id}/private-key` (`lib/Controller/EncryptionSuiteController.php:254`), guarded by `#[VaultKeyProofRequired(binds: ['encryptedPrivateKey'], subject: 'routeParam:id', ...)]` at `:249`: a signature by that suite's own private key.
- Vault-key proofs (`docs/ARCHITECTURE.md` section 4.2, `lib/Service/VaultKeyProofService.php:60` to `:74` for the purposes) and the reflection test `tests/Unit/Controller/VaultKeyProofAttributesTest.php`.
- Administrator force-revocation (ADR-005): `EncryptionSuiteController::forceRevoke`, route `appinfo/routes.php:40`, UI `src/components/settings/AdminSuiteSection.vue:21` and `:180`. Revocation fires `EncryptionSuiteRevokedEvent` (`lib/Listener/EncryptionSuiteRevokedListener.php:45`).
- CA issuance: `lib/Service/CertificateIssuanceService.php:114` `signPublicKey()` and `:211` `signCsr()`.
- HPKE base mode (X25519, HKDF-SHA256, AES-256-GCM) in `src/crypto/hpke.js` (`generateRecipientKeyPair` `:200`, `seal` `:315`, `open` `:342`).
- Lock screen: `src/views/LockScreen.vue`, route `/lock` (`src/router/guards.js`).

## Goals / Non-Goals

**Goals:**

- A user who forgot their master password regains their own vault, with the same key pair and every secret readable.
- The server never holds the recovery private key, a user's private key, or the master password in usable form.
- No single person can recover an account alone when the threshold is two or more.
- A user knows whether they are enrolled, and knows when a recovery happened.

**Non-Goals:**

- Recovery for application-owned suites. Applications hold their own keys; ADR-005 force-revocation stays their route.
- Splitting the recovery private key into threshold shares (Shamir). See D2.
- Administrators reading a user's secrets. Recovery gives the key to the user's own browser only.
- A recovery key held on paper or offline hardware outside Keepiq.

## Decisions

### D1: The escrow mirrors emergency access

Enrolment is `buildRecoveryEnvelope(privateKeyPem, recoveryCertificatePem)`: the same hybrid envelope, the same client-side build, the same "server stores only the envelope" rule, with the organisation recovery certificate as recipient. The user's browser needs the private key PEM, so enrolment asks for the master password once (at the next unlock under the required policy, where the password is already in hand).

Wrapping the private key rather than the raw unlock key means a routine master password change keeps the enrolment valid; a key rotation replaces it (D7).

### D2: Officers hold the recovery key one copy each, and the server enforces a threshold

An administrator names the officers and a threshold `k` (1 to the number of officers). An officer then generates the recovery key pair in their browser (`generateKeyPair`), gets the certificate issued through `signPublicKey()`, wraps the private key PEM to every officer's current suite certificate with the same hybrid envelope, posts the wrapped copies, and discards the key. The server stores the certificate and one wrapped copy per officer.

A recovery needs `k` distinct officer approvals, each a vault-key proof. Only then does the server release the user's enrolment envelope, and only to an officer who approved.

Alternative considered: Shamir splitting of the recovery private key so that `k` officers must each contribute a share. Rejected for this change: the shares must be combined in one browser, which then holds the full key anyway, and every officer change forces a new split and a full re-enrolment. The honest limit of D2 is stated in the security section.

### D3: The request carries a one-time key and a verification phrase

On the lock screen a user who is enrolled can choose "Forgot your master password?". Their browser generates an X25519 key pair (`src/crypto/hpke.js`), keeps the private key in IndexedDB as a non-extractable key bound to the request id, and posts the public key. The request expires after 72 hours. Both the user's screen and each officer's approval dialog show a verification phrase derived from the SHA-256 of that public key. The officer compares the phrase with the user over a channel they trust (in person or by phone) before approving. A phrase mismatch means the key was swapped on the way, and the officer declines.

### D4: Handoff through one approving officer's browser

When the threshold is met, the next approving officer who opens the request fetches the enrolment envelope and their own wrapped copy of the recovery key. Their browser opens the copy with their own suite key, opens the enrolment envelope with the recovery key, seals the user's private key PEM to the request public key with HPKE (`info` `keepiq-account-recovery-v1`, `aad` the request id), posts the sealed result, and discards everything.

The user's browser (the one that made the request) fetches the sealed result, opens it with the request private key, asks for a new master password under the existing strength rules, wraps the private key with it, and calls `PUT /api/v1/suites/{id}/private-key` with a vault-key proof signed by the recovered key. The server marks the request fulfilled and deletes the sealed result.

### D5: Policy and enrolment

App config `account_recovery_policy`: `off` (default), `optional` or `required`. Under `optional` a user enrols or withdraws in their personal settings. Under `required` the web app enrols at the next unlock and tells the user, and withdrawal is refused. Before enrolling, the browser shows the recovery certificate's fingerprint, which administrators publish internally, and checks that the certificate chains to the instance CA.

### D6: Approvals are proven, not just clicked

`POST /api/v1/recovery/requests/{id}/approve` carries `#[VaultKeyProofRequired(binds: ['id'], subject: 'active', purpose: 'approve-account-recovery')]`, so an approval needs the officer's master password, not just their session. It is added to `VaultKeyProofAttributesTest`. An officer cannot approve a request for their own account.

### D7: Enrolments and officer copies follow the suite

A user's enrolment belongs to their suite. After a compromise-recovery rotation the rotating browser, which holds the new key, builds a fresh enrolment to the current recovery certificate, as it re-envelopes emergency contacts. A listener on `SuiteMigrationCompletedEvent` removes enrolments still on the old suite, and one on `EncryptionSuiteRevokedEvent` removes the revoked suite's enrolment and its open requests.

An officer's wrapped copy is migrated the same way during their own rotation. Removing an officer deletes their copy; because they may have opened it before, the admin section offers to rotate the recovery key. Rotation creates a new key; each enrolled user's browser re-enrols at the next unlock; the old key is retired once no enrolment uses it, or at a deadline the administrator sets.

### D8: Revocation remains the fallback

`AdminSuiteSection.vue` warns when the suite being force-revoked belongs to an enrolled user: "This user is enrolled in account recovery. Recovering keeps their secrets; revoking deletes their enrolment." ADR-005's endpoint does not change.

### Endpoints

Admin (`#[AuthorizedAdminSetting(AdminSettings::class)]` and `#[PasswordConfirmationRequired]`): set officers, threshold and policy; retire a recovery key. Officer (`#[NoAdminRequired]`, in-body officer check): create the recovery key, list requests, approve (D6), decline, fetch the handoff material, post the sealed result, migrate their copy. User (`#[NoAdminRequired]`, own records only): read and write their enrolment, create a request, read their request and its sealed result, complete. All under `/api/v1/recovery/`, registered before the SPA catch-all.

## Security and zero-knowledge

Stored encrypted: each officer's copy of the recovery private key (hybrid envelope to that officer's suite certificate), each enrolment (the user's suite private key, hybrid envelope to the recovery certificate), and the short-lived sealed handoff (the user's private key, HPKE to the request key, deleted on completion). Stored in plain: the recovery certificate and fingerprint, officer ids, the threshold, the policy, request states, approvals, and the request public key. The server never holds a key that opens any of the ciphertext it stores (ADR-003).

What this change honestly adds to the trust model:

- **One officer's browser sees the recovered private key** for the moment of the handoff (D4). That is the price of recovery without a server-held key; Bitwarden and Passbolt have the same property. The user is told who handled it, and the web app offers a key rotation right after recovery.
- **Any single officer can open the recovery key.** The threshold is enforced by the server, which alone releases enrolment envelopes. A colluding server operator and one officer could bypass it. D2 records why Shamir splitting is not chosen now.
- **The recovery certificate and the request key come through the server**, like every recipient certificate in Keepiq sharing and emergency access. The fingerprint check at enrolment (D5) and the verification phrase at recovery (D3) let people detect a swap.

Nothing here lets an administrator read a vault: an administrator who is not an officer holds no copy, and even officers get only ciphertext without an approved request.

## Risks / Trade-offs

- **The request browser must be the completion browser.** The request key lives in that browser's IndexedDB. A user who switches device files a new request.
- **Officers leaving the organisation.** D7's removal plus key rotation handles it; until rotation completes the removed officer may still hold an opened copy.
- **Required policy asks for the master password at unlock anyway**, so enrolment adds no prompt, only a notice.
- **Fewer officers than the threshold** (officers removed) would block every recovery; the admin section refuses a threshold above the officer count and warns when an officer has no active suite.

## Seed data

None. Keepiq owns its tables (ADR-001) and has no OpenRegister register. The Playwright flow creates two officer users and one user through the existing E2E seed script `tests/e2e/ci-seed.sh`.

## Migration

New tables, through a new migration step:

- `keepiq_recovery_keys`: id, certificate, fingerprint, threshold, status (`active`, `retiring`, `retired`), created_by, created_at, retired_at.
- `keepiq_recovery_officers`: id, recovery_key_id, officer_uid, officer_suite_id, wrapped_private_key (TEXT), added_by, added_at.
- `keepiq_recovery_enrolments`: id, user_id, suite_id, recovery_key_id, envelope (TEXT), enrolled_at.
- `keepiq_recovery_requests`: id, user_id, suite_id, enrolment_id, request_public_key, status (`pending`, `approved`, `fulfilled`, `declined`, `expired`), created_at, expires_at, handled_by, sealed_result (TEXT, nullable), fulfilled_at.
- `keepiq_recovery_approvals`: id, request_id, officer_uid, decision, decided_at, unique on request and officer.

The `<version>` in `appinfo/info.xml` must bump.
