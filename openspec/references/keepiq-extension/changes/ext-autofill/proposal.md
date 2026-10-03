---
kind: code
depends_on: [ext-vault-browse, ext-vault-edit]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-generator
  - ext-send
  - ext-settings
  - ext-autofill
---

## Why

The product promise is "Fill in logins on any site, and save new ones as you go", and nothing in the chain so far touches a web page. This change is the last in the chain and turns the vault the earlier changes built into a working password manager: fill from the popup, the context menu and a keyboard shortcut, and offer to save or update a login after the user submits one.

## What Changes

- Mirrors Bitwarden's first-generation autofill: fill from the popup's "Autofill suggestions" section, a "Keepiq" context menu, the `Ctrl+Shift+L` / `Cmd+Shift+L` shortcut, and optional fill on page load.
- URL matching runs in the background on the plaintext `url` with Bitwarden's six match rules (Base domain, Host, Starts with, Exact, Regular expression, Never), default Base domain, public suffix aware.
- The content script gains form field detection, a one-shot fill executor, and login submit detection. It replaces the template's on/off scaffold in `entrypoints/content.ts`.
- A Bitwarden-style notification bar offers "Save" (with folder choice) or "Never for this site" for a new login, and "Update password" when a matching item has a different password.
- The context menu offers Copy username, Copy password and Generate password and copy. Copying from the background uses an offscreen document on Chrome MV3 and the background page on Firefox MV2.
- Manifest additions: `contextMenus`, `clipboardWrite`, `tabs`, `offscreen` (Chrome only), a `commands` entry, and `web_accessible_resources` for the notification bar page.
- Out of scope, each a later change: inline menu on form fields, passkey provider, TOTP autofill after fill, card and identity fill, narrowing the content script `matches` (see CLAUDE.md open decision; autofill needs the broad match to run on any login page, so this change keeps `*://*/*`).

## Capabilities

### New Capabilities
- `autofill`: URL matching, fill from popup, context menu, shortcut and page load, field detection and the credential hand-off to the content script.
- `login-capture`: detection of a submitted login form, the save and update notification bar, excluded domains and the "Ask to add" and "Ask to update" settings.

### Modified Capabilities

## Deviations from Bitwarden

- Match rule is global only. Keepiq stores one plaintext `url` per item and has no per-URI match type, so the "URI match detection" default from settings applies to every item and there is no per-item override.
- "Last used" is local. Keepiq has no last-used field on a secret, so the extension records the last fill time per item id in `storage.local` and uses it for the shortcut, the page-load fill and the suggestion order. It does not roam between devices.
- The context menu appears only on editable fields (Bitwarden shows it on every context). Copy and fill only make sense next to a field, and it keeps the menu out of the way elsewhere. Not forced by the API.
- Saving from the bar names the item after the page host and files it under the system `login` type, because Keepiq requires `key` and a `typeId` on create (ADR-003) and Bitwarden's bar has no type choice either.
- Saving and updating from the bar require an unlocked vault. Encryption only needs the cached certificate, so it would work while locked, but Bitwarden requires unlock and the extension follows that.

## Keepiq API used

- `POST /api/v1/secrets` (save a captured login)
- `PUT /api/v1/secrets/{id}` (update `key` for a captured password change)
- `GET /api/v1/secrets/{id}` (re-fetch before update, ADR-002)
- `GET /api/v1/secret-types` (resolve the `login` type id, already cached by ext-vault-browse)
- `GET /api/v1/folders` (folder choice in the save bar, already cached by ext-vault-browse)

All from ADR-003. Matching and fill use the cached snapshot and need no request.

## Impact

- `entrypoints/content.ts` is rewritten; `entrypoints/background.ts` gains the fill orchestrator, menu builder, command handler and capture queue; `entrypoints/popup/*` gains fill actions; new `entrypoints/offscreen/` (Chrome clipboard) and `entrypoints/notification/` (bar UI).
- New `src/autofill/*`, `src/menus.ts`, `src/last-used.ts`; `src/messages.ts` gains the `fill.*` and `capture.*` envelopes.
- `wxt.config.ts` gains permissions, `commands` and `web_accessible_resources`. Both stores will ask about the new permissions and the `*://*/*` content script at review time.
- New dependency `tldts` for public-suffix-aware base domain matching.
- Settings read from ext-settings: URI match default, autofill on page load, ask to add login, ask to update login, excluded domains, clipboard clear delay, show context menu.
