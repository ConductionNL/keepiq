# Tasks: use-only shares and expiring shares

## 1. Data and resolver

- [x] 1.1 Add the migration step for the new columns on share targets, group shares, team-folder members and secrets, and bump `<version>`. Verify: a PHPUnit migration test asserts the columns and the `access_expires_at` index. Done: `Version001008Date20261002181000`, `tests/Unit/Migration/UseOnlyExpiringSharesMigrationTest.php`; `<version>` 0.3.4-unstable.20261002181000.
- [x] 1.2 Add `ShareRestrictionResolver` that materialises `use_only` (all grants use-only) and `access_expires_at` (latest end, none wins) onto each copy, and call it from every share, group share and membership write. Verify: PHPUnit for single grants, mixed grants and removal of the last restricted grant. Done: `lib/Service/ShareRestrictionResolver.php`, `ShareRestriction::combine`; `tests/Unit/Service/ShareRestrictionResolverTest.php`, `ShareRestrictionTest.php`.

## 2. Setting the flags

- [x] 2.1 Accept `useOnly` and `expiresAt` on `ShareController::create`, `createBatch`, `registerBatch` and `GroupShareController::create`, and add `PATCH /api/v1/shares/{id}` for the owner. Verify: PHPUnit refuses a past date and a change by the recipient. Done: `PATCH /api/v1/shares/{id}` (`ShareController::update`); `tests/Unit/Controller/UseOnlyShareRefusalTest.php`.
- [x] 2.2 Accept `useOnly` (with grade `read` only) and `expiresAt` on team-folder member add and `PATCH`. Verify: PHPUnit refuses `useOnly` with `write` or `manage`. Done: `TeamFolderService::setMemberGrade`/`addMember`; `TeamFolderServiceTest::testUseOnlyIsRefusedWithTheWriteGrade`.
- [x] 2.3 Add the checkbox, the date and the honest use-only text to the share dialog and the team-folder dialog, with the writing skill. Verify: vitest renders both options and the text. Done: `src/components/share/ShareRestrictionFields.vue` in `BulkShareDialog`, `GroupShareForm`, `TeamFolderDialog`; `tests/components/ShareRestrictionFields.spec.js`.

## 3. Server refusals and use recording

- [x] 3.1 Refuse every share source that is a use-only or expiring copy (direct, batch, register-batch, group, link share, delegation, handover) and exclude such copies from team-folder subtree refs. Verify: PHPUnit for each path. Done: `OnwardShareGuard` on create, batch, register-batch, group, link, delegation and handover, and `subtreeSecretRefs`; `UseOnlyShareRefusalTest`, `UseOnlyServerRefusalTest`.
- [x] 3.2 Refuse recipient updates and sync of a use-only copy, its version history ciphertext for the recipient, and its value ciphertext in the recipient's GDPR export. Verify: PHPUnit for each refusal. Done: `SecretService::update`, `ShareSyncService::syncUpdate`, `SecretVersionAccessGuard::requireReadableVersion`; the server GDPR metadata (`GdprService::collectMetadata`) never carried value ciphertext, and the client-assembled vault half goes through `serializeVault`, which now leaves use-only copies out.
- [x] 3.3 Add `POST /api/v1/secrets/{id}/used` (recipient of a use-only copy only) with a whitelisted `secret.used` audit event visible in the owner's activity tab. Verify: PHPUnit for the route guard and the audit metadata. Done: `UseOnlyController::used`, `UseOnlyUseRecorder`, `secret.used` whitelisted with `copyId`; `UseOnlyShareRefusalTest`.

## 4. Client refusals

- [x] 4.1 Web app: hide reveal and copy of the value and additional fields, the edit dialog and version reveal for use-only copies, and exclude them from exports and bulk share. Verify: vitest for `PasswordField.vue`, `CopyButton.vue`, `SecretDetailSidebar.vue`, `VersionHistoryPanel.vue` and the export builder. Done: `PasswordField`, `CopyButton`, `SecretDetailSidebar` (no edit, share, structured value or version history), `SecretListItem`, `serializeVault`, `BulkShareDialog`; `tests/components/UseOnly.spec.js`.
- [x] 4.2 Extension: strict site match, password-field-only fill, no display or copy, no save prompt, and a `used` report per fill. Verify: vitest in `tests/extension/` for each rule. Done: `browser-extension/src/lib/useOnly.js` wired in the worker and content script; `tests/extension/useOnly.spec.js`.
- [x] 4.3 CLI: refuse `show`, `get key` and `copy key` for use-only copies and mark them in `list`. Verify: a Go test with a use-only row. Done: `cli/main.go`, `cli/clipboard.go`; `cli/useonly_test.go` (not run locally: no Go toolchain on the build machine).

## 5. Expiry

- [x] 5.1 Filter expired copies on every recipient read path (list, get, extension match, unified search, offline manifest). Verify: PHPUnit asserts an expired copy is not returned by any of them one second after its end. Partly done: the detail read answers as unknown after the end (`UseOnlyServerRefusalTest::testAnExpiredCopyAnswersAsUnknown`); the list, extension match, unified search and offline manifest filter in SQL (`SecretMapper::excludeAccessExpired`), which no unit test can reach without a database. Finished 4 Oct: `tests/Unit/Db/ExpiredCopyReadPathsTest.php` writes real rows one second past their end and reads them through each path's own entry point (`SecretService::list` and `search`, `SecretService::get`, `ExtensionController::match`, `SecretSearchProvider::search`, `OfflineManifestService::buildForUser`), with a copy ending tomorrow and one without an end as positive controls. Run against a Nextcloud 35 instance on PostgreSQL 16: 6 tests green; with `excludeAccessExpired` removed, 5 of 6 red (the detail read has its own check). It skips on a bare checkout, like the other database-backed tests.
- [x] 5.2 Add `ExpireSharesJob` (every 15 minutes) that revokes expired targets, removes expired memberships and re-runs the resolver. Verify: PHPUnit for a direct share, a group-derived share and a membership. Done: `ExpireSharesJob`, `ShareExpiryService`, `ExpiredGrantRemover`; `tests/Unit/Service/ShareExpiryTest.php`.
- [x] 5.3 Add the `share_access_ending` and `share_access_ended` notifications and the owner's end-of-access notice with the rotation hint. Verify: PHPUnit for the subjects and the two owner texts. Done: `KeepiqNotifier::renderAccessEndSubject`; `tests/Unit/Notification/AccessEndNotifierTest.php`, `ShareExpiryTest`.
- [x] 5.4 Make the offline client refuse to decrypt a copy past `accessExpiresAt`. Verify: vitest with a snapshot holding an expired copy. Done: `useSecretStore().decryptSecret` refuses past `accessExpiresAt`; `tests/components/UseOnly.spec.js`.

## 6. End to end

- [ ] 6.1 Add a Playwright flow: the owner shares a login use-only with a one-day end; the recipient sees no reveal or copy in the web app; after the end date (clock moved in the test) the secret is gone from the recipient's list. Verify: the Playwright spec passes in the E2E job. **Live check owed**: the Playwright flow needs the E2E job; not written in this change.

## Acceptance criteria

- An owner can mark a user share, group share or read-grade team-folder membership as use-only, and give any of them an end date.
- Keepiq's web app, extension and CLI never show, copy, export or edit the value of a use-only copy; the extension still fills it on the matching site.
- The share dialog states that use-only is enforced by Keepiq's apps and can be bypassed by a technically skilled recipient.
- The server refuses every onward share of a use-only or expiring copy, recipient edits of a use-only copy, and its version reveal.
- From its end date the server serves an expired copy on no read path, and the job removes it within 15 minutes.
- The owner is told when access ended, with a rotation hint when the recipient could see the value.
