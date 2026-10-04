# Tasks: generator, vault and send in the browser extension

## 1. Shared modules

- [x] 1.1 Move the send crypto into `src/send/sendCrypto.js` and use it in the send store. Verify: `tests/vitest/sendCrypto.spec.js` and the existing send access test.

## 2. Worker

- [x] 2.1 Vault, generator and send handlers with injected collaborators. Verify: `tests/extension/vaultSendGenerator.spec.js` (no blob in a list, no plaintext in a save or send body, policy refusals, bounds).
- [x] 2.2 API client calls for the list, folders, types, trash and sends. Verify: the popup integration test's recorded requests.

## 3. Page

- [x] 3.1 The sign-up field offer. Verify: `tests/extension/passwordSuggest.spec.js` (field detection, trusted clicks only, fills password and confirmation).

## 4. Popup

- [x] 4.1 Tabs and the Generator, Vault and Send views. Verify: `tests/extension/popupVaultSendGenerator.spec.js` on the real popup and router with a real RSA vault, and a live run of the built extension in Chromium.
