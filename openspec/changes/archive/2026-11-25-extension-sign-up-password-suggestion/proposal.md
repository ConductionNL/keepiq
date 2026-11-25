---
kind: code
---

# Suggest a strong password in a sign-up field

## Why

The browser extension generates passwords, passphrases and usernames in its Generator tab (`browser-extension/entrypoints/popup/views/Generator.tsx`) and from the context menu (`browser-extension/src/menus.ts`). It does not offer one in the page itself. The field classifier already tells a sign-up form apart (`browser-extension/src/autofill/fields.ts`, group kind `signUp`), but only the save prompt reads it (`src/autofill/capture-collector.ts`). The requirement in `openspec/specs/extension-generator` was written for the earlier extension, and `browser-extension/openspec/changes/ext-autofill` left the suggestion out of scope.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `clients-24` | Generate a password or passphrase in the browser extension, and get one suggested in a sign-up field. | `partial`: the Generator tab and the context menu work; no field offers a password |

## What Changes

- **An offer on a new-password field.** When a field the classifier marks as a new password gets focus, the inline list shows "Use a strong password". The user's own click fills a password generated under the organisation policy into that field and its confirmation field.
- **No offer where it does not belong.** A field for the current password gets no offer, and a click that page script dispatched does nothing.

## Security

- The password is generated in the extension worker under the policy the generator already reads (`browser-extension/src/generator/policy.ts`). The page receives only the filled value, the same as a login fill.
- Only a trusted user click counts, so a page cannot make the extension fill a password it chose the moment for.

## Capabilities

### Modified Capabilities

- `extension-generator`: the suggestion requirement gains a scenario for a current-password field.

## Impact

Browser extension only. No server change.
