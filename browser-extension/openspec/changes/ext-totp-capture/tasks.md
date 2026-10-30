## 1. Key and QR code

- [x] 1.1 Write `src/totp/authenticator-key.ts`, a port of the web app's `normaliseAuthenticatorKey`, `authenticatorKeyError` and `otpauthFromQrText` on top of `parseTotpSeed()`, returning codes, not texts
  - Tests: the web app's `authenticatorKey.js` cases, a key in groups, lower case, a link kept as typed, an HOTP and a Steam link refused, empty as no key
- [x] 1.2 Add `jsqr` 1.4.0 to `package.json` and write `src/totp/qr-scan.ts`: `scanVisibleTab()` with `captureVisibleTab` as PNG, the picture scaled to at most 2048 pixels on its longer side, jsQR loaded with `import()` and `inversionAttempts: 'attemptBoth'`, answering the link or `no_qr`, `not_totp` or `no_picture`
  - Tests with the capture and pixel read injected: `test-site/totp-qr.svg`'s matrix, a QR code holding `https://example.com/`, a blank picture, a capture that throws
  - The Chrome build has jsQR in its own chunk, not in the popup's first one
- [x] 1.3 Add `activeTab` to `permissions` in `wxt.config.ts`
  - Both `.output/*/manifest.json` list it; ends with `npm run build` and `npm run build:firefox`

## 2. Messages and background

- [x] 2.1 Extend `src/messages.ts` per design.md § Message contract: `totp` on `ItemDraft`, `totpSaved` and `totpLeftOut` on `EditableItem`, `partner_upgrade_required` with `partners` and `requiredVersion` in the write errors
  - Typecheck is clean
- [x] 2.2 In `src/vault/write.ts`: `totpSaved` on an edit fetch, `false` with `totpLeftOut` on a clone, the seed never decrypted for either; `totp` in `holds()` and `passkeys` with it; `recordOpened()` keeping both ciphertexts whatever `shownInFull()` says
  - Tests: a login with and without a seed, a clone of one, a fetch whose `totp` ciphertext the cache does not hold refused as `conflict`, no seed in any reply
- [x] 2.3 `totp` on create and update in `src/vault/write.ts`: normalised and checked again, encrypted; refused with `invalid` on create of another type, on a non-empty update of a cached row that is not a `login`, and for a key that does not read; `''` sent as is; a patch with only `totp` goes through share-save
  - Tests: create with and without a key, update replace, remove and keep, a note's `''`, a note's non-empty `totp` refused; neither storage area holds the key in plain after a save

## 3. Share-save

- [x] 3.1 `totp` as a value field in `src/vault/share-save.ts`: in the sparse copies with the patch's null-ness and `''`; `lookUp()` reading `valueFields` and `verifiedPartners()` keeping it with the certificate; `sealWhole()` adding `totp` and `passkeys` for a partner that lists each, null otherwise
  - Tests: a copy for a removed key carries `''`, a username change carries no `totp`; a password change sends a listing partner the stored seed, and a partner that lists nothing `totp` and `passkeys` null
- [x] 3.2 The partner refusal (spec § A key a partner cannot store stops the save): a new key on a row that held none, to a partner without `totp` in `valueFields`, fails with `partner_upgrade_required` before any write; the server's 409 maps to it with `requiredVersion`; the popup's text in `en.yml` and `nl.yml`
  - Tests: adding a key with an older partner, replacing and removing with one, the server's 409, a partner that lists `totp`

## 4. Popup

- [x] 4.1 Write `entrypoints/popup/components/AuthenticatorKeyField.tsx`: the masked input with Show, the help text, Scan QR code on page with its status line and errors, disabled in the pop-out window with its hint; the saved state with Replace, Remove and Keep the saved key, Remove only when asked; focus per design.md § Focus
  - Popup tests in both projects: each scan outcome, each saved-state choice and where focus lands, keyboard use and the screen reader names; no seed in the DOM of an edit form
- [x] 4.2 Put the field in `ItemForm.tsx`: on Login in add, edit and clone; Remove only on another type whose row holds a key; the clone note; `totp` into the draft and the patch only when entered, replaced or removed; "Not a valid authenticator secret" on Save
  - Tests: the create and update bodies of spec § The key is saved encrypted and § A saved key stays hidden, the unsaved changes warning after a scan
- [x] 4.3 Drop `totp` from the Type select for new items in `TypeSelect.tsx` (kept for an existing item's own type) and from Default item type in `views/settings/Vault.tsx`, a stored `totp` read as Login
  - Tests: the add form's types, an existing Authenticator item's edit form, a stored `totp` default
- [x] 4.4 Every new text in `locales/en.yml` and `locales/nl.yml`; `locales.test.ts` passes
  - Ends with `npm run build` and `npm run build:firefox`

## 5. Test site, docs and verification

- [x] 5.1 Add `test-site/qr-website.html`, a setup-like page whose QR code holds `https://example.com/`, linked from `test-site/index.html`
- [x] 5.2 Update ARCHITECTURE.md (modules, the field, share-save's value fields), SECURITY.md (the picture of the tab, where the key lives), ADR-002 (`activeTab`, the seed never sent to the popup), ADR-003 (`totp` on create and share-save, `valueFields`); `../docs/browser-extension/using.md` and `privacy.md` on scanning
  - Every comment and doc line follows CLAUDE.md § Comments & docs
- [ ] 5.3 Walk every scenario in `specs/` on Chrome (`npm run dev`) and Firefox (`npm run dev:firefox`) on `totp-setup.html` and `qr-website.html`: scan, save, then fill the confirm field from the inline list; replace, remove, clone; the pop-out window; a shared login against the dev server
  - Every scenario holds on both browsers, or the difference is in design.md
  - `npm test`, `npm run test:e2e`, typecheck and lint are clean
  - Both targets build
