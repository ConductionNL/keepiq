## 1. Manifest

- [x] 1.1 Add `privacy` to `optional_permissions` in `wxt.config.ts` for the Chromium build only
  - `.output/chrome-mv3/manifest.json` lists it under `optional_permissions`; `.output/firefox-mv2/manifest.json` does not list it

## 2. Background

- [x] 2.1 Read the state of both settings (on, off, or managed elsewhere), set both to `false`, and clear those this extension controls, behind typed messages in `src/messages.ts` that answer codes, never text
  - Tests: on, off, `not_controllable` and `controlled_by_other_extensions` for each setting, no permission held, and the browser without `privacy.services.autofillAddressEnabled`

## 3. Autofill view

- [x] 3.1 Add the toggle to the Autofill view on Chromium: request `privacy` in the click, set on grant, clear and remove the permission on off, the note when managed elsewhere, disabled unless one setting is still ours
  - Tests: Firefox shows no entry, declined permission keeps it off, the managed note, and the strings in en and nl

## 4. Verification

- [ ] 4.1 On Chrome (`npm run dev`): turn it on, check that `chrome://password-manager/settings` and `chrome://settings/addresses` show the setting controlled by Keepiq and that the test site's username field shows no Chrome dropdown; turn it off and check both are back to the user's choice
