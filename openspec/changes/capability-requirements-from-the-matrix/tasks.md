# Tasks: requirements for five matrix rows that no spec states

## 1. Compare versions (vault-29)

- [ ] 1.1 Add a Compare action to the version history panel that takes two versions, or one version and the current value, decrypts both in the browser and shows them side by side with changed fields marked. Verify: vitest on the field comparison, including additional fields added or removed, and a Playwright flow on a secret with two versions.

## 2. Link share end date (sharing-14)

- [ ] 2.1 Add an optional end date to the link share dialog and pass it as `expires_at`; refuse a date in the past. Verify: vitest on the dialog and PHPUnit on the access check after the date.

## 3. Privileged login access (sharing-26)

- [ ] 3.1 Add a "needs approval" flag on a shared login, an access request with a time window, and an approval with an optional address range; refuse to serve the recipient's copy outside it and record the refusal in the audit trail. Verify: PHPUnit on the window and range checks, including an IPv6 range, and on the audit event.

## 4. Application secrets list (apps-14)

- [x] 4.1 The application detail page lists the secrets in the application's vault by name, type and creation date (`src/components/application/ApplicationSecretsPanel.vue`). Verify: the existing panel and `GET /api/v1/secrets?ownerType=application&ownerId={id}`.

## 5. Risky event alerts (audit-15)

- [x] 5.1 The five events notify the people concerned when recorded (`HoneyTripwireService`, `EmergencyAccessService`, `CompromiseContainmentService`, `DeviceApprovalService`, `RecoveryRequestService`). Verify: the existing PHPUnit tests of those services.

## Acceptance criteria

- Each of the five rows records a requirement in this change that states it.
- `openspec validate capability-requirements-from-the-matrix --strict` passes.
