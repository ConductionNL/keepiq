---
kind: code
---

# Admin vault policies

## Why

An organisation cannot stop users exporting their personal vault, cannot demand two-factor login before a vault opens, and cannot require that work logins live in a team folder. This change adds those three vault policies.

| Row | Capability | What keepiq does today |
|---|---|---|
| admin-10 | Enforce rules such as two-factor login or a ban on exporting personal vaults | Keepiq has no policy to require two-factor login or to block personal vault export. Nextcloud can enforce two-factor for the whole login, which also guards keepiq, but that is a server setting, not a vault rule. |
| admin-22 | Require that work logins are kept in the organisation's vault rather than in personal vaults | Every secret starts in the creator's personal vault; nothing forces work logins into a team folder. |

Matrix: keepiq `openspec/parity/capabilities.json`

### Demand

- admin-22, tender: https://www.tenderned.nl/aankondigingen/overzicht/295007
- admin-10: no demand row.

### Competitors rated yes

- Bitwarden (admin-10): "bitwarden/server@v2026.9.1 src/Core/AdminConsole/Enums/PolicyType.cs:5 TwoFactorAuthentication, :19 DisablePersonalVaultExport; bitwarden/clients@web-v2026.9.0 apps/web/src/app/admin-console/organizations/policies/policy-edit-definitions/two-factor-authentication.component.ts; ... Note: Require two-step login and remove individual vault export are among 23 policies."
- 1Password (admin-10): "https://support.1password.com/team-policies/ : 'Two-factor authentication' enforcement and further sign-in, sharing and file policies"
- Keeper (admin-10): "https://docs.keeper.io/enterprise-guide/roles/enforcement-policies : 2FA enforcement ('it cannot be disabled by the user') and Import and Export restriction ('RESTRICT_EXPORT')"
- Bitwarden (admin-22): "bitwarden/server@v2026.9.1 src/Core/AdminConsole/Enums/PolicyType.cs:10 OrganizationDataOwnership, :52 'Enforce organization data ownership'; ... apps/web/src/locales/en/messages.json:8554 'Require all items to be owned by an organization, removing the option to store items at the account level' ..."

### Scope of admin-10

The decision specifies the vault half only: a policy that blocks personal vault export, and a policy that requires the user to have Nextcloud two-factor login enabled before the vault unlocks. Enforcing two-factor login itself stays with Nextcloud.

## What Changes

- Three vault policies in the admin settings, next to the org password policy. Each is off by default and can be scoped to Nextcloud groups (empty means every user).
- **Block personal vault export.** `POST /api/v1/export/events` refuses the encrypted backup, plaintext CSV, CXF and CXP modes for an in-scope user. The browser already aborts the download when that report fails, and the export dialog hides the blocked modes. The GDPR access package stays available.
- **Require Nextcloud two-factor login before unlock.** For an in-scope user without an enabled Nextcloud two-factor provider (backup codes do not count), the server withholds the wrapped private key from the suite endpoints and the offline manifest, and refuses to create a first suite. The lock screen explains why and links to the Nextcloud security settings.
- **Keep work logins in team folders.** For an in-scope user and in-scope secret types (default `login`, `api_key`, `database`), creating, importing or moving a secret outside a team folder subtree the user owns is refused.
- A member with a `write` grade can save a new secret straight into a team folder they do not own. Their browser encrypts the value for the folder owner and every member; the server authorises on the grade.
- In-scope users see which of their personal items break the ownership policy, with a move action.
- Every policy change is audited with a before and after snapshot.

## Capabilities

### New Capabilities

- `vault-policies`: organisation-wide vault rules an administrator switches on per group: an export ban, two-factor login before unlock, and team folder ownership of work logins.

### Modified Capabilities

None.

## Impact

- **Backend**: a new `VaultPolicyService` (read, scope check, update, audit); checks in `ExportController::events()`, `EncryptionSuiteController::index()`, `show()` and `create()`, `OfflineManifestService`, `SecretService::create()` and `update()`, and `ImportController::batchCreate()`; a new `POST /api/v1/team-folders/{id}/secrets` for write-grade contributions; the policy keys join `GET /api/settings/policy`.
- **Frontend**: a new `VaultPolicySection.vue` in the admin settings; `ExportDialog.vue` hides blocked modes; `LockScreen.vue` shows the two-factor notice; the secret form restricts the folder picker; the health report lists personal items that break the ownership policy.
- **Database**: none. The policies are app config keys.
- **Security**: the export ban and the ownership policy govern the supported clients; a tampered client can still read what it can decrypt, as the export audit already states. The two-factor policy withholds ciphertext the user needs to unlock, so it holds for every client, including the CLI and the browser extension.
- **Cross-app**: none. OpenConnector and other application vaults are not users and are outside every policy.
