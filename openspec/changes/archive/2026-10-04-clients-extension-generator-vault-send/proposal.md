---
kind: code
---

# Generator, vault and send in the browser extension

## Why

The Keepiq browser extension fills logins, passkeys and one-time codes, but a user who signs up somewhere new has to open Nextcloud to make a password, and cannot look up, change or share a vault item without it. The separate `ConductionNL/keepiq-extension` repository specified these flows (changes `ext-generator`, `ext-vault-browse`, `ext-vault-edit`, `ext-send`) with no code behind them. This change builds the core of those flows in the extension that ships, `browser-extension/` in this repository.

## What Changes

- **Generator tab.** Passwords and passphrases in the popup, made with the web app's generator (`src/generator/generator.js`, from `client-side-key-generator`) under the org policy.
- **A strong password in sign-up fields.** On a field for a new password, the page shows an offer in a closed shadow root. On the user's click the worker generates a password under the org policy and fills the field and its confirmation. The existing save prompt then offers to save the login.
- **Vault tab.** Search by name and address, filter by folder and type, open an item to copy or show its username and password, add and edit items with a folder and a generated password, and move items to the trash.
- **Send tab.** Create a send from text or a username and password (also from an open item), with a view limit and an expiry preset; the link carries the key in its fragment and is shown once. List and end your sends.
- **One send-crypto module** (`src/send/sendCrypto.js`) for the web app and the extension.

## Scope against the keepiq-extension specs

Built: generator options, passphrases, the policy clamp and copy; vault search, folder and type filters, item detail with copy and reveal, add, edit and trash, folder picker; send creation with the credential body, view bounds, expiry presets including Custom up to 720 hours, the link shown once, send from item, list and end.

Not in this change, each a follow-up: the bottom tab bar layout and pop-out window, the offline vault cache and sync triggers, generator history and per-account remembered options, the username generator, the folder manager, clone and move, additional fields, card and identity sections, and password-protected sends (the web app's Argon2 build does not bundle into the extension yet; the Send tab points users to the web app for those).

## Capabilities

### New Capabilities

- `extension-generator`: the popup Generator tab and the sign-up field offer.
- `extension-vault`: browse, search, open, add, edit and trash vault items in the popup.
- `extension-send`: create, list and end sends in the popup.

## Impact

- `browser-extension/src/background/vault-handlers.js`, `router.js` (new messages; `generate-for-field` is the one a content script may send, and it returns only a random password)
- `browser-extension/src/lib/api.js`, `lib/send-form.js`, `lib/vault-index.js`
- `browser-extension/src/content/password-suggest.js`, `content-script.js`
- `browser-extension/src/popup/` (tabs, three views, styles)
- `src/send/sendCrypto.js`, `src/store/modules/ephemeralSend.js`
