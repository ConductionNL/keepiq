---
kind: code
---

# Approve a new device from a device that is already unlocked

## Why

Every device unlocks the Keepiq vault by typing the master password. A user setting up the browser extension, or opening Keepiq on a new laptop, has to type a long password on a keyboard they may not trust yet, and there is no way to let a device they already use vouch for the new one. Competitors let a signed-in device approve the new one, and some let an administrator do it.

| Row | Capability | What Keepiq does today |
|---|---|---|
| crypto-24 | Approve a sign-in on a new device from a device where you are already signed in, or have an administrator approve it. | Sign-in is Nextcloud's; a new device unlocks the vault with the user's passphrase, and there is no approve-from-another-device or admin approval flow. |

Matrix: keepiq `openspec/parity/capabilities.json`

Not built. A search for device approval, auth requests or login requests in `lib/`, `src/` and `appinfo/routes.php` finds nothing; the web app unlocks at /lock with the master password or a passkey (`src/store/modules/passkey.js:193`), and the extension with the master password (`browser-extension/src/popup/popup.js:182`).

### Demand

- changelog: https://github.com/bitwarden/server/releases/tag/v2026.4.0

### Competitors rated yes

- Bitwarden: "bitwarden/server@v2026.9.1 src/Api/Auth/Controllers/AuthRequestsController.cs:78 POST auth-requests, :91 admin-request, :104 PUT {id} (approve); bitwarden/clients@web-v2026.9.0 libs/angular/src/auth/login-approval/login-approval-dialog.component.ts; bitwarden_license/bit-web/src/app/admin-console/organizations/manage/device-approvals/device-approvals.component.ts ..."
- Passbolt: "passbolt/passbolt_api@v5.16.0 plugins/PassboltEe/AccountRecovery/config/routes.php:52 POST /account-recovery/requests, :61 admin review, :68 responses; plugins/PassboltCe/Mobile/config/routes.php:27 transfer from a signed-in browser ... A new mobile or desktop device is set up from a browser where the user is signed in, and a lost browser can be restored through an account recovery request that an admin approves (Pro)."
- Nextcloud Passwords: "marius-wieschollek/passwords@2026.9.0 src/lib/Controller/Link/ConnectController.php:136 request() from the new client, :221 confirm() by the signed-in web session, :260 apply(codes); src/vue/Dialog/ConnectClient.vue with ConnectConfirm.vue; :242 new client notification Note: PassLink lets a new extension or app sign in by being approved from a browser where you are already signed in; no admin approval option."

## What Changes

- On the lock screen at /lock, and in the locked extension popup, a user can choose "Approve from another device". The new device makes a one-time key pair, sends the public key, and shows a verification phrase.
- The user's unlocked web app shows the request with the device details and the same phrase. After comparing the phrase and confirming their master password (or passkey), the user approves. Their browser seals the vault unlock key to the new device's one-time key; the server only relays the sealed result.
- The new device opens the sealed key and unlocks for this session. The server deletes the sealed result on first pickup.
- Requests expire after 15 minutes, are rate-limited, are announced by a Nextcloud notification, and are audited. The user can deny a request and is then pointed to end their other Nextcloud sessions.
- An administrator-held path exists only for users enrolled in organisation account recovery (change `crypto-organisation-account-recovery`): the device request becomes a recovery request for that device, approved by recovery officers, and the server stays keyless.
- An administrator can turn device approval off.

## Capabilities

### New Capabilities

- `new-device-approval`: device approval requests, the verification phrase, approval with a sealed unlock key, pickup, denial, limits, and the officer path for enrolled users.

### Modified Capabilities

None.

## Impact

- **Backend**: a `DeviceApprovalController` and service, a new vault-key proof purpose `approve-device`, a background job that expires requests, and a notification subject.
- **Frontend**: the lock screen and an approval dialog in the web app; the locked view of the extension popup.
- **Database**: one new table; a migration and a `<version>` bump.
- **Security**: the server relays only an HPKE-sealed unlock key it cannot open; approval needs the master password or a passkey on the approving device; the phrase defends against a swapped key.
- **Cross-app**: none. The officer path depends on `crypto-organisation-account-recovery` being built first.
