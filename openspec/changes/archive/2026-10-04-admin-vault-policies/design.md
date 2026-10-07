# Design: admin vault policies

## Context

Read at development `4c214a9d`.

Policy area today:

- `lib/Service/AdminSettingsService.php:132` `getAdminSettings()` and `:232` `updateAdminSettings()` read and write app config keys in validated groups. `:254` `getPolicy()` serves the user-visible policy floor through `PasswordPolicyService`.
- `lib/Service/PasswordPolicyService.php:49` lists the org password policy keys; `:142` `getPolicy()` is what `GET /api/settings/policy` returns (`lib/Controller/SettingsController.php:284`, `#[NoAdminRequired]`).
- `src/components/settings/OrgPasswordPolicySection.vue:13` renders the org password policy as a `CnSettingsSection`; `src/views/settings/Settings.vue:17` mounts it.
- `lib/Event/Audit/AuditEventTypes.php:248` whitelists `before` and `after` for `PASSWORD_POLICY_UPDATED`, the pattern a policy audit follows.

Export today:

- Export runs in the browser. `lib/Controller/ExportController.php` `events()` records the export mode (`encrypted-backup`, `plaintext-csv`, `cxf`, `cxp`) for the session user.
- `src/store/modules/export.js:68` `reportExport()` posts to `/api/v1/export/events`, and `exportBackup()` reports before offering the download (`:93` to `:97`): a failed report aborts the export. `exportCsv()`, `exportCxf()` and `exportCxpSealed()` follow the same order. `exportGdprPackage()` does not report an export mode.

Unlock today:

- `src/store/modules/session.js:61` `unlock()` fetches `GET /api/v1/suites` and unwraps `privateKey` of the active suite in the browser. The CLI (`cli/internal/client/client.go:84`) and the browser extension (`browser-extension/src/lib/api.js:85`) read the same endpoint.
- `lib/Controller/EncryptionSuiteController.php:89` `index()` and `:117` `show()` return `EncryptionSuite::jsonSerialize()`, which includes `privateKey` (`lib/Db/EncryptionSuite.php:203`). `:176` `create()` stores a first suite generated in the browser.
- `lib/Service/OfflineManifestService.php:89` puts the active suite blob in the offline snapshot.
- Nextcloud offers `OCP\Authentication\TwoFactorAuth\IRegistry::getProviderStates(IUser)` (since 14), which returns every provider id with its enabled state for a user.

Ownership today:

- `lib/Service/SecretService.php:239` `create()` stores any `folderId` as given; `:825` `update()` can move a secret. `lib/Controller/ImportController.php:98` creates secrets in batches.
- `lib/Service/TeamFolderQueryService.php:333` `ancestorTeamFolders()` (private) walks a folder's ancestors to the team folders above it.
- `lib/Service/TeamFolderService.php:620` `resolveGrade()` computes a member's effective `read` or `write` grade; `lib/Controller/ShareController.php:438` `writeContext()` hands a write-grade member the owner-row material for a fan-out update (`folder-permission-grades` spec).
- `lib/Repair/SeedSecretTypes.php:62` seeds the types, including `login`, `api_key` and `database`.

## Goals / Non-Goals

**Goals:**

- An administrator switches each vault policy on for all users or for chosen groups.
- A blocked export fails before any file is offered, in every supported client flow.
- A user without Nextcloud two-factor login cannot unlock or create a vault while the policy applies to them.
- New work logins of an in-scope user end up in a team folder, where the organisation keeps access through the existing offboarding transfer.

**Non-Goals:**

- Enforcing two-factor login itself. Nextcloud's own "Enforce two-factor authentication" setting stays the tool for that.
- Detecting MFA done at an external identity provider. An administrator who relies on it scopes the policy to the groups that do not use single sign-on.
- Moving existing personal secrets on the server. The server cannot re-encrypt; the user moves them in the browser.
- Blocking the GDPR access package. It is a legal right of access and stays available.

## Decisions

### D1: One policy service, app config keys, group scope per policy

`VaultPolicyService` owns seven app config keys: `vault_export_disabled`, `vault_export_disabled_groups`, `vault_require_two_factor`, `vault_require_two_factor_groups`, `vault_org_ownership`, `vault_org_ownership_groups` and `vault_org_ownership_types`. `appliesTo(policy, userId)` is true when the policy is on and the group list is empty or shares a group with the user (`IGroupManager::getUserGroupIds()`). `AdminSettingsService::updateAdminSettings()` calls a new `updateVaultPolicySettings()` group; `getPolicy()` adds the three effective booleans for the session user, so the browser knows what applies without learning the group lists.

Alternative considered: a policy table with one row per policy. Rejected: every other keepiq setting is app config, and three switches do not need a table.

### D2: The export ban rides the existing report-before-download order

`ExportController::events()` returns 403 with `code: export_disabled_by_policy` when the ban applies to the session user. Because every export action reports before it offers the file, the browser aborts. `ExportDialog.vue` hides the modes up front from `getPolicy()`. The GDPR package does not call `events()` and is not affected.

Alternative considered: a new export-token endpoint that the browser must call first. Rejected: the report already sits before the download in all four modes, so a second round trip adds nothing.

### D3: Two-factor gating withholds the wrapped private key

When `vault_require_two_factor` applies and `IRegistry::getProviderStates()` returns no enabled provider other than `backup_codes`, the server:

- returns the suite list and single suite without `privateKey`, adding `unlockBlocked: "two_factor_required"`;
- leaves the suite out of the offline manifest, so an offline unlock is impossible too; the browser drops its stored snapshot on this signal;
- refuses `POST /api/v1/suites` with 403 and the same code, so no first suite is created.

`LockScreen.vue` shows "Your organisation requires two-factor login before you can open your vault" with a link to `/settings/user/security`. The CLI and the browser extension read the same endpoint and fail with the same code.

Alternative considered: return 403 from `GET /api/v1/suites`. Rejected: other screens read suite status and certificates from that endpoint and would break for a reason that has nothing to do with them.

### D4: Ownership policy checks the target folder on every write path

For an in-scope user and an in-scope type, `SecretService::create()`, `update()` (when `folderId` changes) and the import batch refuse a target folder that has no team folder owned by the user among its ancestors. The check exposes `ancestorTeamFolders()` as a public query on `TeamFolderQueryService`. The refusal is 403 with `code: org_ownership_required`. Types outside `vault_org_ownership_types` stay personal. The default types are `login`, `api_key` and `database`, the credential types a tender means by work logins.

Alternative considered: count any folder shared with the user as organisational. Rejected: a folder the user owns but never shared is still personal, and a folder owned by someone else cannot hold the user's own secret today.

### D5: Write-grade members contribute into a team folder

A member who owns no team folder must still be able to comply. `POST /api/v1/team-folders/{id}/secrets` accepts a new secret from a member whose effective grade on the target folder is `write`. The member's browser encrypts the value under the folder owner's certificate (write without read, as the secret request fill already does) and under every effective member's certificate, including their own. The server stores the owner row with `owner_id` set to the folder owner, registers the derived copies through `TeamFolderShareService`, and audits the creation with the member as actor.

Alternative considered: let each member share a personal folder as their own team folder. Rejected: the organisation then depends on every user adding the right members, and offboarding transfers only work when a successor already holds a copy.

### D6: Personal items that break the policy are listed, not moved

The health report gains a "Not in a team folder" list for in-scope types. Its "Move to a team folder" action changes `folderId` into an owned team folder (the fan-out then runs), or, for a non-owner, contributes through D5 and deletes the personal copy after the contribution succeeds.

## Security and zero-knowledge

- The server never sees a plaintext value or the master password in any of these flows. The contribution in D5 carries only ciphertext encrypted in the member's browser; the server checks the grade and the folder, never the content.
- Stored plain: the policy switches, group lists and type lists (app config). Stored encrypted: nothing new; secret fields stay RSA ciphertext as today.
- The two-factor policy withholds the AES-wrapped private key. Withholding ciphertext weakens nothing, and it is the only lever that holds for every client.
- The export ban and the ownership policy govern supported clients. A tampered browser can still read what it can decrypt; the proposal says so, as the export controller docblock already does.
- A contributor can write a wrong value into the owner's row. A `write` grade already lets that member change every copy, so D5 adds no new trust.

## Risks / Trade-offs

- An in-scope user without two-factor login loses vault access the moment the policy is switched on. The admin section warns with the number of in-scope users without an enabled provider before saving.
- Users who rely on identity provider MFA are blocked until an administrator scopes them out. The admin section says so next to the switch.
- The ownership policy can block a user who owns no team folder and holds no `write` grade. The error names the policy and the health report lists what to do.

## Seed data

None. The policies ship off. PHPUnit tests mock `IRegistry` and `IGroupManager`; the Playwright test switches a policy on through the admin settings.

## Migration

None. No table or column; seven app config keys with defaults read on demand. `<version>` in `appinfo/info.xml` does not need a bump for schema reasons.
