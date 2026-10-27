---
kind: code
depends_on: [ext-vault-browse, ext-vault-edit, ext-share-sync, ext-settings]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-share-sync
  - ext-i18n
  - ext-generator
  - ext-send
  - ext-theme
  - ext-settings
  - ext-autofill
---

## Why

The product promise is "Fill in logins on any site, and save new ones as you go", and nothing in the chain so far touches a web page. This change turns the vault the earlier changes built into a working password manager: fill from the popup, a list beneath the login field, the context menu and a keyboard shortcut, and offer to save or update a login after the user submits one.

## What Changes

- Mirrors Bitwarden's autofill: fill from the popup's "Autofill suggestions" section, the inline autofill menu on login fields, a "Keepiq" context menu, the `Ctrl+Shift+L` / `Cmd+Shift+L` shortcut, and optional fill on page load.
- URL matching runs in the background on the plaintext `url` with Bitwarden's six match rules (Base domain, Host, Starts with, Exact, Regular expression, Never), default Base domain, public suffix aware.
- The content script gains form field detection, a one-shot fill executor, and login submit detection. It replaces the template's on/off scaffold in `entrypoints/content.ts` and runs in every frame.
- An inline list opens beneath a focused username or password field with the logins matching that frame, a locked state with "Unlock", or a no-match state. It is an extension page in an isolated iframe; picking a login fills that field's form through the same one-credential fill as the popup. Driven by ext-settings' "Show autofill suggestions on form fields" (`inlineSuggestions`).
- Fill on page load honours ext-settings' "Default autofill setting for login items" (`defaultAutofill`).
- A Bitwarden-style notification bar offers "Save" (with folder choice) or "Never for this site" for a new login, and "Update password" when a matching item has a different password.
- The context menu offers Copy username, Copy password and Generate password and copy. Copying from the background uses the existing offscreen document on Chrome MV3 and the background page on Firefox MV2.
- Manifest additions: `contextMenus`, two `commands` entries (the autofill shortcut and `Alt+Shift+K` to reach the save bar), and `web_accessible_resources` for the notification bar and inline list pages. `tabs`, `alarms`, `clipboardWrite` and Chrome's `offscreen` are already declared (ext-vault-browse).
- Out of scope, each a later change: passkey provider, TOTP autofill after fill, card and identity fill, password suggestion in sign-up fields, narrowing the content script `matches` (see CLAUDE.md open decision; autofill needs the broad match to run on any login page, so this change keeps `*://*/*`).
- Also later: answering a pending save or update from the toolbar popup, which is keyboard-reachable and works behind page modal dialogs.

## Capabilities

### New Capabilities
- `autofill`: URL matching, fill from popup, context menu, shortcut and page load, field detection and the credential hand-off to the content script.
- `inline-list`: the list beneath a login field: which fields qualify, its contents and states, keyboard and screen reader use, positioning, frames, and its isolation from the page.
- `login-capture`: detection of a submitted login form, the save and update notification bar, excluded domains and the "Ask to add" and "Ask to update" settings.

### Modified Capabilities

- `vault-list` (ext-vault-browse): the suggestions section shows on non-http(s) pages with "Autofill is not available on this page", its cards fill, it lists in last-used order, and blocked rows are not listed.
- `popup-shell` (ext-vault-browse): a non-http(s) tab gets "Autofill is not available on this page" instead of a hidden suggestions section.

## Deviations from Bitwarden

- Match rule is global only. Keepiq stores one plaintext `url` per item and has no per-URI match type, so the "Default URI match detection" setting applies to every item and there is no per-item override.
- "Last used" is local. Keepiq has no last-used field on a secret, so the extension records the last fill time per item id in `storage.local` and uses it for the shortcut, the page-load fill and the suggestion order. It does not roam between devices.
- The context menu appears only on editable fields (Bitwarden shows it on every context). Copy and fill only make sense next to a field, and it keeps the menu out of the way elsewhere. Not forced by the API.
- Saving from the bar names the item after the page host and files it under the system `login` type, because Keepiq requires `key` and a `typeId` on create (ADR-003) and Bitwarden's bar has no type choice either.
- Saving and updating from the bar require an unlocked vault. Encryption only needs the cached certificate, so it would work while locked, but Bitwarden requires unlock and the extension follows that.
- The inline list has no button inside the field and one setting, not two; it opens on focus of an empty qualifying field and on a click in one.
- The inline list renders in the frame that holds the field, not in the top frame, because top-frame placement needs frame offsets from `webNavigation`, a permission this change does not add.
- On Chrome and Firefox alike the inline list shows only in the top frame and in subframes whose ancestors are all same-origin, because the extension cannot check a cross-origin ancestor's visibility, and a mask on one hides the list from Chrome's visibility report too. Bitwarden shows it in cross-origin frames.
- A pick refused because the page changes how the list is shown keeps the list open with an explanation instead of closing it, since some assistive styling (a zoom extension, or a dark-mode filter without the popover API) refuses every pick.
- The inline list has one fixed height in every state, so the page cannot count the matches by its size.
- The inline list offers no "New item" and shows only logins (no cards, identities or passkeys), which are later changes.
- The inline list asks the insecure-page question inside the list, not through a page `confirm()`.

## Keepiq API used

- `POST /api/v1/secrets` (save a captured login)
- `PUT /api/v1/secrets/{id}/share-save` (update `key` for a captured password change: always through `updateItem` and share-save, shared or not, ext-share-sync)
- `GET /api/v1/secrets/{id}/write-context` (the copy's grade and source, read by `updateItem` before it saves)
- `GET /api/v1/suites` (the active suite, checked before encrypting a save or update)
- `POST /api/v1/shares/recipient-certificates` and `POST /api/v1/federation/recipient-certificate` (recipient certificates for that share-save)
- `GET /api/v1/secrets/{id}` (re-fetch before update, ADR-002)
- `GET /api/v1/secret-types` (resolve the `login` type id, already cached by ext-vault-browse)
- `GET /api/v1/folders` (folder choice in the save bar, already cached by ext-vault-browse)

All from ADR-003, none newer than `MIN_KEEPIQ_VERSION`, so nothing is gated in `KEEPIQ_FEATURES`. Matching, fill and the inline list use the cached snapshot and need no request.

## Impact

- `entrypoints/content.ts` is rewritten and runs in all frames; `entrypoints/background.ts` gains the fill orchestrator, inline list sessions, menu builder, command handler and capture queue; `entrypoints/popup/*` gains fill actions; `entrypoints/offscreen/` gains clipboard writes; new `entrypoints/notification/` (bar UI) and `entrypoints/inline-list/` (list UI), both vanilla DOM.
- New `src/autofill/*`, `src/menus.ts`, `src/last-used.ts`; `src/messages.ts` gains the `fill.*`, `inline.*` and `capture.*` envelopes.
- `wxt.config.ts` gains `contextMenus`, `commands` and `web_accessible_resources`. Both stores will ask about the new permission and the `*://*/*` content script at review time.
- `tldts` is already a dependency through `src/vault/match.ts`; the matcher builds on it.
- Reads ext-settings' autofill settings (design.md lists the keys) and adds the delivered entries to `AUTOFILL_FEATURES`.
- ADR-002 (what the inline list may show, its session) and ADR-004 (vanilla DOM for pages injected into web pages) are updated; `popout()` in `src/background/requests.ts` is exported.
