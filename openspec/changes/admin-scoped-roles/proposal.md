---
kind: code
---

# Admin scoped roles

## Why

Keepiq administration is all or nothing: a person either gets the whole Keepiq admin section or nothing, plus a hard-coded `vault_admin` group for offboarding and handover. An organisation cannot give a helpdesk only offboarding, or an auditor only the audit log.

| Row | Capability | What keepiq does today |
|---|---|---|
| admin-11 | Hand out admin roles with only the permissions a person needs | There are two coarse levers: Nextcloud's delegation of the whole Keepiq admin section, and a hard-coded vault_admin group that unlocks offboarding and admin handover. The handover now has a route, a controller call and a UI panel (f13ad8e6, closing #184), so both levers work end to end. There is still no role editor and no per-permission role, so a person cannot be given only the permissions they need: partial. |

Matrix: keepiq `openspec/parity/capabilities.json`

### Demand

No demand row.

### Competitors rated yes

- Bitwarden: "bitwarden/server@v2026.9.1 src/Core/AdminConsole/Enums/OrganizationUserType.cs:5 Owner, :6 Admin, :7 User, :9 Custom; src/Core/AdminConsole/Models/Data/Permissions.cs:8 13 granular permission flags (event logs, import/export, reports, collections, groups, users, policies, SSO, SCIM, account recovery); ... Note: Custom role with 13 granular permissions besides owner and admin."
- 1Password: "https://support.1password.com/custom-groups/ : custom groups with chosen administrative permissions (Business)"
- Keeper: "https://docs.keeper.io/enterprise-guide/delegated-administration : administrative permissions granted per role and scoped to nodes"
- HashiCorp Vault: "hashicorp/vault@v2.1.1 vault/policy.go:25 fine-grained capabilities per path; ui/app/components/policy-form.ts:170 policy editor; ui/app/router.js access.namespaces (Enterprise namespaces for delegated admins) ..."

### Missing half

admin-11 is partial. Built: Nextcloud delegation of the whole Keepiq admin section, and the `vault_admin` group for offboarding and admin handover. Missing: named admin roles with a chosen set of permissions.

## What Changes

- The Keepiq admin settings split into five delegable areas, each its own Nextcloud admin settings class with a name: General, Policies, Applications and machine access, People and offboarding, Audit and compliance.
- A role is a Nextcloud group. An administrator creates a group such as "Keepiq helpdesk" and delegates the areas it needs on Nextcloud's "Administration privileges" page. That page is the role editor.
- Every Keepiq admin endpoint names exactly one area in its `#[AuthorizedAdminSetting]` guard. The inline `isAdmin()` checks in nine controllers and three services move to the same area model.
- A delegated user sees only the sections of the areas they hold.
- The in-app admin handover and the offboarding action check the People and offboarding area. The `vault_admin` group keeps working as an alias for that area for one release, then is removed.
- The Keepiq admin settings show which areas exist and what each one covers.

## Capabilities

### New Capabilities

- `admin-scoped-roles`: Keepiq administration split into named, delegable areas, so a Nextcloud group can hold only the areas a person needs.

### Modified Capabilities

None.

## Impact

- **Backend**: five settings classes under `lib/Settings/` implementing `IDelegatedSettings`; an `AdminAreaAuthorizer` for checks inside services and in-app panels; the guards on 14 attribute-guarded methods in four controllers and the inline admin checks move to one area each; `info.xml` lists the five classes.
- **Frontend**: the admin bundle renders only the sections of the area it is mounted for, read from initial state; `AdminHandoverPanel.vue` reads the area check instead of the `vault_admin` flag.
- **Database**: none. Nextcloud stores delegations in its own table.
- **Security**: narrower grants. Instance administrators keep every area. A delegated user cannot reach an endpoint outside their areas, because Nextcloud's middleware refuses it before the controller runs.
- **Cross-app**: none.
