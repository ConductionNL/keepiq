---
kind: code
---

# Publish the browser extension in the stores and fill a one-time code on the next step

## Why

The Keepiq browser extension fills logins and one-time codes in code, but no user can install it without building it from source and loading it unpacked. There is no release or store pipeline: `.github/workflows/` holds `cli-release.yml` for the Go CLI and nothing for `browser-extension/`. And the one-time code is only filled when the code field is already on the page at fill time, while most sites ask for the code on the page after the password.

| Row | Capability | What Keepiq does today |
|---|---|---|
| clients-01 | Fill in logins on websites through a browser extension. | Autofill works in code: the popup lists owned secrets matching the site, decrypts in the worker and fills on click. The extension is not packaged or published, and matching only covers secrets the user owns, not shared or team-folder secrets. |
| clients-04 | Fill in the current one-time code together with the login. | After filling a login the worker looks for a totp-typed secret on the same host, fills a detected one-time-code field and copies the code with a 30 second clipboard clear. The OTP field has to be on the page at fill time, and the extension is not distributed. |

Matrix: keepiq `openspec/parity/capabilities.json`

Both rows are partial.

- clients-01. Built: URL matching (`browser-extension/src/popup/popup.js:45` to `lib/Controller/ExtensionController.php:180`), decrypt in the worker (`browser-extension/src/background/service-worker.js:113`) and fill (`browser-extension/src/content/content-script.js:95`). Missing, from the decision: a packaged, signed extension in the browser stores, and matching over shared and team-folder secrets. The second half does not hold up against the code and is not specified here: a shared or team-folder copy is a `Secret` row owned by the recipient (`lib/Service/RecipientSecretCopyService.php:112` sets `ownerType` `user` and `:113` sets `ownerId` to the recipient; team folders create their copies through the same service at `lib/Service/TeamFolderShareService.php:295`), so the owner-scoped match at `lib/Controller/ExtensionController.php:195` already returns them.
- clients-04. Built: filling a code field present at fill time (`browser-extension/src/background/service-worker.js:137` to `:141`, `browser-extension/src/content/content-script.js:115`) and copying the code. Missing, from the decision: filling a one-time code field that appears after the login step.

### Demand

No demand row.

### Competitors rated yes

clients-01:

- Bitwarden: "bitwarden/clients@web-v2026.9.0 apps/browser/src/autofill/services/autofill.service.ts:481 doAutoFill, :712 doAutoFillActiveTab; apps/browser/src/manifest.v3.json:137 autofill_login shortcut ..."
- 1Password: "https://support.1password.com/save-fill-passwords/ : 'After you've saved your username and password for a website, 1Password can fill them'"
- Passbolt: "passbolt/passbolt_browser_extension@v5.16.0 src/all/background_page/pagemod/webIntegrationPagemod.js injects the web integration; src/all/background_page/controller/autofill/AutofillController.js:149 fillCredentials ..."
- Keeper: "https://docs.keeper.io/user-guides/browser-extensions : KeeperFill 'Autofill Your Passwords' on websites"
- Nextcloud Passwords: "not in the cloned repos, docs rating kept: marius-wieschollek/passwords@2026.9.0 src/js/Services/AppStoreService.js:19 ... https://git.mdns.eu/nextcloud/passwords/-/wikis/Administrators/Feature-Comparison ..."

clients-04:

- Bitwarden: "bitwarden/clients@web-v2026.9.0 apps/browser/src/autofill/services/autofill.service.ts:104 TotpService injected, :427 autoCopyTotp$ copies the current code to the clipboard after filling ..."
- 1Password: "https://support.1password.com/one-time-passwords/ : '1Password automatically fills your one-time password'"
- Passbolt: "passbolt/passbolt_browser_extension@v5.16.0 src/all/background_page/controller/autofill/AutofillController.js:99 reads totp from the decrypted secret, :101 fillCredentials with username, password and totp ..."
- Keeper: "https://docs.keeper.io/user-guides/browser-extensions#autofilling-2fa-codes : 'Upon autofilling your username and password via KeeperFill, when prompted, a stored two-factor code will also be autofilled'"

## What Changes

- A release pipeline for `browser-extension/`: tested, built per browser, packed, and on an `extension-v*` tag submitted to the Chrome Web Store, Firefox Add-ons (AMO) and Microsoft Edge Add-ons, with the packages attached to a GitHub release.
- `browser-extension/build.mjs` gains a `--target chrome|firefox` option that writes the right manifest for each browser.
- Store listings with a privacy policy, a justification per permission, and user-facing copy that follows the writing rules. The unused `scripting` permission is removed.
- A version handshake: the pair response carries the Keepiq server version, and the extension tells the user when the server is too old for it.
- Enterprise rollout documentation: force-install by store id through Chrome and Edge policy, and a signed Firefox package on the GitHub release.
- After a login fill, the extension remembers a short-lived, one-shot intent to fill the one-time code on that tab. When a code field appears on the same site within five minutes, the extension computes the current code and fills it.

## Capabilities

### New Capabilities

- `extension-store-release`: packaging, signing, publishing and versioning of the browser extension.

### Modified Capabilities

- `extension-totp-autofill`: adds a requirement for filling the one-time code on the step after the login.

## Impact

- **Backend**: `ExtensionController::pair()` adds the server version to its response (`lib/Controller/ExtensionController.php:123` already returns capabilities). No new route.
- **Frontend**: extension only: `build.mjs`, `manifest.json`, `service-worker.js`, `content-script.js`, the popup. The web app is untouched.
- **Database**: none. No migration and no `<version>` bump for the server.
- **Security**: store credentials live in a protected GitHub environment; the pending code intent holds no seed and no code; the zero-knowledge model of `browser-extension-autofill` is unchanged.
- **Cross-app**: none.
