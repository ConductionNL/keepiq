---
kind: code
depends_on: [ext-vault-edit, ext-generator, ext-autofill, ext-totp-autofill, ext-card-identity-fill]
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
  - ext-toolbar-icon
  - ext-totp-autofill
  - ext-totp-capture
  - ext-card-identity-fill
  - ext-context-menu
---

## Why

The "Keepiq" context menu shows only on editable fields, so a user who right-clicks the page next to a login form finds no Keepiq at all. Entries without matches are disabled, which reads as a feature that is turned off. There is no way to add an item from the menu, and a fill that finds no form fails silently.

## What Changes

- Mirrors Bitwarden's context menu:
  - **Anywhere on the page.** The menu shows on the page, frames, selections, links, editable fields, images, video and audio, not only on editable fields.
  - **Every entry is a submenu.** Autofill login, Copy username, Copy password, Copy verification code, Autofill identity and Autofill card always open a list, also for one item. With nothing to list it holds one disabled line, "No logins available" and so on. No entry is ever disabled itself.
  - **Add entries.** Autofill login, Autofill identity and Autofill card end with Add login, Add identity and Add card, then Open Keepiq. Each Add opens the popup on an add form for that one type, without the Type select.
  - **Order:** Autofill login, Copy username, Copy password, Copy verification code; a separator; Autofill identity, Autofill card; a separator; Generate password and copy.
- **No form to fill.** A menu fill that finds no field for the item says so in the page, in a short notice in the save bar's corner.
- **Labels are item names only,** as today. The username is never in a title (design.md § Names only).
- One `context-menu` capability holds the whole menu; the requirements ext-autofill, ext-totp-autofill and ext-card-identity-fill wrote for it move there.
- Out of scope:
  - "Copy custom field name". In Bitwarden it copies the right-clicked field's `name` or `id`, for a custom field that Bitwarden then fills by that name. Keepiq's autofill never fills `additionalFields`, so the name would have no use. A change that fills custom fields can add it.
  - Filling a generated password into the right-clicked field. Generate password and copy stays copy-only, as today.

## Capabilities

### New Capabilities
- `context-menu`: where the menu shows, its entries and submenus, the empty lines, the Add entries, the no-form notice, copy and generate, the locked and hidden states.

### Modified Capabilities
- `autofill` (ext-autofill, as ext-card-identity-fill left it): "Context menu on editable fields" moves to `context-menu`.
- `totp-autofill` (ext-totp-autofill): "Copy verification code" in the context menu moves to `context-menu`.
- `item-editing` (ext-vault-edit): an add form fixed to one type.

## Deviations from Bitwarden

- **Item names only, never `<name> (<username>)`.** On Chrome the browser writes every menu title of a service-worker extension to its on-disk extension state, and ADR-002 writes nothing decrypted to storage. Two items with the same name look alike in the menu. Not forced on Firefox, kept the same for one behaviour.
- **No custom field name copy** (above).
- **A no-form notice.** Bitwarden does nothing when a menu fill finds no field.
- **The menu does not show on the toolbar icon's own right-click menu.** Bitwarden's `all` context includes it on Chrome.
- **Open Keepiq ends each Autofill submenu.** It is the way to more than 10 items.

## Keepiq API used

None. The menu reads the cached snapshot, and the Add forms save through `item.create` (ext-vault-edit). Nothing is gated in `KEEPIQ_FEATURES`.

## Impact

- `src/menus.ts`: the contexts, the entry tree, the empty lines, the Add entries and the notice after a fill.
- `src/autofill/fill.ts` and the card and identity branch of ext-card-identity-fill: a result that says no frame had a field.
- `src/messages.ts`: `notice.show` to the top frame, and `popup.intent` from the popup.
- `entrypoints/content.ts` and `src/autofill/notification-host.ts`: host the notice.
- `entrypoints/notification/`: the notice mode of the bar page.
- `entrypoints/popup/`: read the intent on connect; `ItemForm` with a fixed type.
- `locales/en.yml`, `locales/nl.yml`; ARCHITECTURE.md, SECURITY.md, `../docs/browser-extension/using.md`.
- No new permission: `contextMenus` exists (ext-autofill).
