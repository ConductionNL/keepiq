---
kind: code
---

# Automatic confirmation of new team folder members

## Why

A new team folder member gets no secrets until the folder owner opens the team folder dialog and runs the key fan-out. When the owner is on leave, the new colleague waits. This change confirms new members automatically, without breaking zero knowledge.

| Row | Capability | What keepiq does today |
|---|---|---|
| admin-25 | New members are confirmed automatically, without an administrator handing over access by hand | A new team folder member gets access only when the owner's browser runs the key fan-out; there is no automatic confirmation. |

Matrix: keepiq `openspec/parity/capabilities.json`

### Demand

- changelog: https://github.com/bitwarden/clients/releases/tag/web-v2026.3.1

### Competitors rated yes

- Bitwarden: "bitwarden/server@v2026.9.1 src/Core/AdminConsole/Enums/PolicyType.cs:27 AutomaticUserConfirmation ('Automatically confirm invited users'); bitwarden/clients@web-v2026.9.0 apps/web/src/app/admin-console/organizations/policies/policy-edit-definitions/auto-confirm-policy.component.ts:33 AutoConfirmPolicy ..."
- HashiCorp Vault: "hashicorp/vault@v2.1.1 vault/identity_store_group_aliases.go:21 group-alias maps an external group (OIDC, LDAP) to a Vault group and its policies. Note: Vault encrypts server-side, so there is no key handover to confirm; a new member gets the policies of their mapped groups at first login. ..."
- Nextcloud Passwords: "marius-wieschollek/passwords@2026.9.0 src/lib/Controller/Api/ShareApiController.php:181 canShareWithUser() accepts any Nextcloud user; src/js/Actions/Share/CreateShareAction.js:90 #disableCse() moves shared items to server-side encryption, so no key handover is needed ..."

## What Changes

- An admin policy switch "Automatically confirm new team folder members" (`team_folder_auto_confirm`, off by default) in the Policies area of the Keepiq admin settings.
- With the switch on, the key fan-out for a new member runs in the unlocked browser of any authorised confirmer: the folder owner, or a member whose effective grade on the folder is `write`. It runs on unlock and every 15 minutes while the vault stays unlocked, with no click.
- A new endpoint `GET /api/v1/team-folders/pending-confirmations` tells a confirmer which folders have members waiting, with their certificates.
- `POST /api/v1/team-folders/{id}/shares` accepts rows from a `write`-grade confirmer who re-encrypts their own current copy, not only from the owner.
- The new member gets the existing "team folder shared" notification; the owner gets a notice naming who confirmed whom.
- With the switch off, nothing changes: the owner runs the fan-out as today.

## Capabilities

### New Capabilities

- `team-folder-auto-confirm`: new team folder members receive their key copies automatically from any authorised member's unlocked browser, under an admin policy switch.

### Modified Capabilities

None.

## Impact

- **Backend**: a pending-confirmations query next to `TeamFolderService::reconcile()`; `registerFanOutShares()` accepts a `write`-grade confirmer with a copy freshness check; the policy key joins `AdminSettingsService` and `getPolicy()`; a new notification subject for the owner.
- **Frontend**: an `autoConfirm()` action in `src/store/modules/teamFolder.js` started after unlock in `src/store/modules/session.js`; a switch in the admin Policies area; the team folder dialog shows who confirmed and what still waits.
- **Database**: none.
- **Security**: the server still never decrypts. Only members already trusted to write a value for the whole team may hand a copy to a new member, and only from a copy as new as the source.
- **Cross-app**: none.
