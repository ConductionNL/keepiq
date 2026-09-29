---
kind: code
---

# Organisation account recovery through named recovery officers

## Why

A Keepiq user who forgets their master password loses every secret they own. By design no administrator can restore access: the server never holds a usable key (ADR-003), and ADR-005 makes administrator force-revocation the lost-password route, which gives the user a fresh, empty vault. Organisations that keep business credentials in Keepiq need a way back that does not make the server a key holder.

| Row | Capability | What Keepiq does today |
|---|---|---|
| crypto-11 | Let an administrator restore a user's access after a forgotten master password. | By zero-knowledge design an administrator cannot restore access to a user's secrets. The admin can force-revoke the locked suite so the user can set up a fresh vault, but the old secrets stay unreadable unless the user has an emergency contact or a backup file. |

Matrix: keepiq `openspec/parity/capabilities.json`

Not built. `src/components/settings/AdminSuiteSection.vue:21` and `:180` only force-revoke a suite. The one escrow in the code is emergency access, which wraps a grantor's private key to a chosen contact's certificate (`src/crypto/emergencyEnvelope.js:52`, `openspec/specs/emergency-access/spec.md`), never to an administrator. The decision records no non-goal: ADR-005 keeps the server keyless, and an opt-in recovery envelope to an organisation recovery certificate keeps it keyless too.

### Demand

No demand row.

### Competitors rated yes

- Bitwarden: "bitwarden/server@v2026.9.1 src/Api/AdminConsole/Controllers/OrganizationUsersController.cs:558 PUT organizations/{orgId}/users/{id}/recover-account, :530 reset-password-enrollment; src/Core/AdminConsole/Enums/PolicyType.cs:17 ResetPassword policy ... Enterprise account recovery lets an admin set a new master password for an enrolled member."
- 1Password: "https://support.1password.com/recovery/ : administrators 'select Begin Recovery'; member gets new Secret Key and password"
- Passbolt: "passbolt/passbolt_api@v5.16.0 plugins/PassboltEe/AccountRecovery/config/routes.php:25 organization-policies, :52 requests, :88 POST /account-recovery/responses ... Pro account recovery escrows an encrypted copy of the user key; an admin with the organisation recovery key approves a request to restore access."
- HashiCorp Vault: "hashicorp/vault@v2.1.1 builtin/credential/userpass/path_user_password.go:39 users/<name>/password; ui/app/router.js access.method.item edit route for userpass users Note: An admin can set a new password for any userpass user; data is server-encrypted so nothing is lost."

## What Changes

- An administrator names recovery officers (Nextcloud users with an active suite) and a threshold of officer approvals, and sets the recovery policy: off, optional or required.
- An officer generates the organisation recovery key pair in their own browser. The certificate is public. The private key is wrapped to each officer's own suite certificate and then discarded; the server never holds it in usable form.
- A user enrols by letting their browser wrap their suite private key to the organisation recovery certificate, exactly as emergency access wraps it to a contact's certificate. Under the required policy the web app enrols at the next unlock and says so.
- A user who forgot their master password files a recovery request from the lock screen. Their browser makes a one-time key pair for the request and shows a verification phrase.
- Officers compare the phrase with the user over a trusted channel and approve with a vault-key proof. Once the threshold is met, one officer's browser opens the enrolment envelope and seals the user's private key to the request key. The user's browser opens it, asks for a new master password, and re-wraps the private key.
- Enrolments and officer copies follow the suite through rotation and revocation, officers can be added or removed, and the recovery key can be rotated. Every step is audited with identifiers only.

## Capabilities

### New Capabilities

- `organisation-account-recovery`: officer-held organisation recovery key, user enrolment, recovery requests with a verification phrase, threshold approval, and client-side handoff of the recovered key.

### Modified Capabilities

None. ADR-005's force-revocation stays as it is; the admin suite section only gains a warning when the user is enrolled.

## Impact

- **Backend**: a `RecoveryController` and services for keys, officers, enrolments and requests; a new vault-key proof purpose `approve-account-recovery`; listeners on suite migration and revocation; notification subjects for requests and outcomes.
- **Frontend**: an admin section for officers, threshold and policy; an officer page for the key and the request queue; enrolment in the user settings; a "Forgot your master password?" path on the lock screen at /lock.
- **Database**: five new tables; a migration and a `<version>` bump.
- **Security**: the server stores only public certificates and ciphertext; the recovery private key exists in usable form only in an officer's browser; a recovered private key passes through one officer's browser, which the user is told about and offered a key rotation for.
- **Cross-app**: none.
