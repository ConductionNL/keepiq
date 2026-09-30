---
kind: code
---

# Offer to save or update a login at once, and pin passkey requests to the page origin

## Why

After a form submit the extension captures the login (`browser-extension/src/content/content-script.js:134-150`) but holds it in memory until the user happens to open the popup (`service-worker.js:240`), and `doSaveCapture` updates only when `payload.id` is set (`:198`) while the capture never carries an id, so a changed password creates a duplicate. For passkeys, the content script forwards `data.origin` from the page's own message instead of `location.origin` (`content-script.js:212`) and nothing checks the relying party id against the origin, so a hostile page can ask for an assertion for another site. Five and three competitors rate yes. Both rows are the same extension surface, so they are one change.

The rows share one screen or service, so they are one change.

### Matrix rows (`keepiq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `clients-03` | Be offered to save or update a login after submitting a form. | `partial`: `partial`: a submitted login is captured but the offer appears only when the popup is opened, and an existing login is never updated |
| `clients-05` | Use the extension as a passkey provider on websites. | `partial`: `partial`: passkey create and sign work, but the relay trusts an origin the page supplies and the native proxy path never activates |

### Demand

- `clients-03`: no demand row.
- `clients-05`: no demand row.

### Competitors rated yes

- `clients-03`, bitwarden: "bitwarden/clients@web-v2026.9.0 apps/browser/src/autofill/background/notification.background.ts:638 triggerAddLoginNotification, :663 getEnableAddedLoginPrompt, :144 bgSaveCipher Note: Add-login and change-password prompts after f"
- `clients-03`, onepassword: "https://support.1password.com/save-fill-passwords/ : '1Password will automatically offer to save your login' and asks to update an existing item"
- `clients-03`, passbolt: "passbolt/passbolt_browser_extension@v5.16.0 src/all/background_page/controller/webIntegration/webIntegrationController.js:34 autosave opens the save-credentials flow (feature autosave-credentials); passbolt/passbolt_styleguide@v5."
- `clients-03`, keeper: "https://docs.keeper.io/user-guides/browser-extensions#save-prompt : 'Keeper will offer to save a password to the vault, if you login manually on a site'; 'Prompt to Change' policy for updates"
- `clients-03`, nextcloud-passwords: "not in the cloned repos, docs rating kept: marius-wieschollek/passwords@2026.9.0 code not public for this (passwords-webextension repo not read); docs rating kept: https://git.mdns.eu/nextcloud/passwords/-/wikis/Administrators/Fea"
- `clients-05`, bitwarden: "bitwarden/clients@web-v2026.9.0 apps/browser/src/autofill/fido2/background/fido2.background.ts; libs/common/src/platform/services/fido2/fido2-authenticator.service.ts:188 creates passkey in a login Note: The extension intercepts W"
- `clients-05`, onepassword: "https://support.1password.com/save-use-passkeys/ : save and sign in with passkeys in the browser extension"
- `clients-05`, keeper: "https://docs.keeper.io/user-guides/browser-extensions#passkeys : create a passkey and log in with a passkey through KeeperFill"

## What Changes

- Show an in-page prompt (a closed shadow root bar) after a submit: Save, Update or Not now, with Update offered when a saved login matches the site and username.
- Match the captured login against saved logins by origin and username and send the matched id with the capture.
- Take the origin for passkey requests from `location.origin` in the content script and check that the relying party id is a registrable suffix of it before any assertion.
- Request the optional native proxy permission when the user enables the passkey provider, or remove the dead path.

## Capabilities

### New Capabilities

- `clients-save-prompt`
- `clients-passkey-origin`

### Modified Capabilities

- None in delta form.

## Impact

- **Extension**: `content-script.js`, `service-worker.js`, `popup.js`, `src/passkey/orchestrator.js`, manifest permissions.
- **Backend**: none.
- **Security**: closes the forged origin defect recorded on `clients-05`.
- **Dependency**: none; store release is `clients-extension-store-release`.
