## 1. Policy settings

- [ ] 1.1 Add `VaultPolicyService` with the policy keys, validation, `appliesTo()` and a `VAULT_POLICY_UPDATED` audit event (before and after snapshot, whitelisted in `AuditEventTypes`). Verify with a PHPUnit test for group scope, invalid types and the audit metadata.
- [ ] 1.2 Wire `updateVaultPolicySettings()` into `AdminSettingsService::updateAdminSettings()` and add the effective booleans for the session user to `getPolicy()`. Verify with a PHPUnit test that `getPolicy()` never returns the group lists.
- [ ] 1.3 Add `VaultPolicySection.vue` next to `OrgPasswordPolicySection.vue`, with group pickers (`NcSelect` with `inputLabel`) and the count of in-scope users without two-factor login. Verify with a vitest in `tests/components/` and the nc-input-labels hydra gate.

## 2. Export ban

- [ ] 2.1 Refuse `POST /api/v1/export/events` with 403 `export_disabled_by_policy` for in-scope users. Verify with a PHPUnit test in `tests/Unit/Controller/ExportControllerTest.php` for all four modes.
- [ ] 2.2 Hide the blocked modes in `ExportDialog.vue` and keep the GDPR package. Verify with a vitest that no download starts when the report is refused.

## 3. Two-factor before unlock

- [ ] 3.1 Withhold `privateKey` and add `unlockBlocked` in `EncryptionSuiteController::index()` and `show()` when the policy applies and no provider other than `backup_codes` is enabled. Verify with a PHPUnit test with a mocked `IRegistry`.
- [ ] 3.2 Refuse `POST /api/v1/suites` and leave the suite out of the offline manifest under the same condition. Verify with PHPUnit tests for `create()` and `OfflineManifestService`.
- [ ] 3.3 Show the two-factor notice in `LockScreen.vue` and drop the offline snapshot on `two_factor_required`. Verify with a vitest for the lock screen and the offline store.
- [ ] 3.4 Map `two_factor_required` to a clear error in the CLI (`cli/`) and the browser extension. Verify with `go test ./...` and the extension vitest suite.

## 4. Team folder ownership

- [ ] 4.1 Make `ancestorTeamFolders()` a public query on `TeamFolderQueryService` and check it in `SecretService::create()`, `update()` and the import batch for in-scope users and types. Verify with PHPUnit tests for create, move and import, including an exempt type.
- [ ] 4.2 Add `POST /api/v1/team-folders/{id}/secrets` for write-grade contributions: owner row plus derived copies, grade checked with `resolveGrade()`, audit with the member as actor. Verify with PHPUnit tests for a `write` member, a `read` member and a non-member, plus the no-admin-idor hydra gate.
- [ ] 4.3 Restrict the folder picker in the secret form to owned team folders and contributable team folders for in-scope types, and add the contribution flow to the secret store. Verify with a vitest for the picker and for the per-recipient encryption.
- [ ] 4.4 Add the "Not in a team folder" list with the move action to the health report. Verify with a vitest for both the owner move and the contribution move.
- [ ] 4.5 Cover the policies end to end. Verify with a Playwright test in `tests/e2e/workflows/` where an administrator switches on the export ban and a user finds the export modes gone, and where a user in scope saves a login into a team folder after being refused a personal folder.

## Acceptance criteria

- With the export ban on for a user, no encrypted backup, CSV, CXF or CXP file is offered to them, and the GDPR package still downloads.
- With the two-factor policy on, a user without an enabled Nextcloud two-factor provider receives no wrapped private key from any endpoint and cannot create a first suite.
- A user who enables a two-factor provider can unlock again without an administrator action.
- With the ownership policy on, an in-scope user cannot save a `login` secret outside a team folder, and can still save a `card` secret personally.
- A write-grade member can save a new secret into a team folder they do not own, and the owner and all members can read it.
- Every policy change produces one audit event with a before and after snapshot.
