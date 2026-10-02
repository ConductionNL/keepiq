# Tasks: the rest of the browser extension

## 1. Generator and send

- [x] 1.1 Class toggles, minimums and ambiguity in the shared generator; the username generator. Verify: `tests/vitest/generator-options.spec.js`.
- [x] 1.2 Generator state in the worker: options, cached policy, email, history and its lifetime. Verify: `tests/extension/generatorState.spec.js`.
- [x] 1.3 Generator tab: sub-tabs, colour-coded output, history, pick mode, lock-screen entry. Verify: `tests/extension/popupVaultSendGenerator.spec.js`, live in Chromium.
- [x] 1.4 Password-protected sends with the bundled Argon2id; copy link for this session's sends. Verify: `tests/extension/vaultSendGenerator.spec.js`; live, the web app opens an extension send with its password.
- [x] 1.5 The Chromium load check opens the real popup. Verify: `node browser-extension/load-check/chromium.mjs` exits 0.

## 2. Item detail and editing

- [x] 2.1 Item form rules (kinds, parts, sparse changes, limits, messages). Verify: `tests/extension/itemForm.spec.js`.
- [x] 2.2 Worker: fresh item fetch with additional fields and metadata, blocked items, sparse save, move. Verify: `tests/extension/vaultSendGenerator.spec.js`.
- [x] 2.3 Popup: detail sections, the form per kind with additional fields, clone, move, unsaved-changes guard. Verify: `tests/extension/popupItemDetail.spec.js`, live in Chromium.

## 3. Folder manager

- [x] 3.1 Folder manager: tree, add, rename, delete with the server's protocol, the not-encrypted notice, New folder in the picker. Verify: `tests/extension/folders.spec.js`, live in Chromium.

## 4. Offline vault cache and sync

- [ ] 4.1 Snapshot, triggers, offline reads.

## 5. Popup shell

- [ ] 5.1 Tab bar, pop-out, remembered tab, theme.
