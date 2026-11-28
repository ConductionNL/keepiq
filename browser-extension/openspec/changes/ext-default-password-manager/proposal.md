---
kind: code
depends_on: [ext-settings, ext-autofill]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-share-sync
  - ext-generator
  - ext-send
  - ext-settings
  - ext-autofill
  - ext-default-password-manager
---

## Why

Chrome's own password manager and autocomplete open their dropdown on the same login fields as Keepiq's inline list, drawn over it. Bitwarden lets the user turn the browser's own off from its settings; Keepiq should too.

## What Changes

- Mirrors Bitwarden's "Make Bitwarden your default password manager" in Settings → Autofill, as "Make Keepiq your default password manager", off by default.
- Turning it on asks for the optional `privacy` permission, then sets Chrome's `privacy.services.passwordSavingEnabled` and `autofillAddressEnabled` to false. Address autofill also gates Chrome's autocomplete of typed values, which is the dropdown on a username field.
- Turning it off clears both settings, so the browser's own choices return, and gives the permission back.
- Chromium only: Firefox's `privacy.services` has only `passwordSavingEnabled`, which leaves its dropdowns in place, so the entry is not shown there, as in Bitwarden.

## Capabilities

### New Capabilities
- `default-password-manager`: the Autofill entry, the permission request, and taking and returning control of the two browser settings.

### Modified Capabilities

None.

## Deviations from Bitwarden

- Chrome's card autofill is left alone: Keepiq has no card items, so there is nothing to take its place.
- Turning it off clears the settings and removes the permission. Bitwarden users report that its control is kept after unchecking.

## Keepiq API used

None.

## Impact

- `wxt.config.ts`: `privacy` in `optional_permissions`, Chromium build only.
- The Autofill settings view gains one entry; the background reads, sets and clears the two settings.
- New strings in `locales/en.yml` and `locales/nl.yml`.
