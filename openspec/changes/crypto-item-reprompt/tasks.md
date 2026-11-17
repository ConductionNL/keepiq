# Tasks: ask for the master password again before a sensitive item is shown or filled

## 1. Backend

- [x] 1.1 Add the `reprompt` column and entity field, accept it on create and update, return it in `jsonSerialize()`, and copy it onto new share copies; bump `<version>`. Verify: PHPUnit for create, update and a new share copy. Migration `Version001021Date20261114130000`; create takes it only when true, update through `SecretUpdateController` as a boolean; `RecipientSecretCopyFactory` copies it onto new copies. Tests in `tests/Unit/Service/SecretRepromptTest.php` and `RecipientSecretCopyCreateTest`.

## 2. Web app

- [x] 2.1 Add `RepromptDialog.vue` and the `useReprompt()` guard on top of `verifyMasterPassword()`. Verify: vitest for accept, reject and cancel. The prompt is an app-wide store (`repromptPrompt.js`) with one dialog in the app shell, like the key-proof prompt; `tests/vitest/reprompt.spec.js`.
- [ ] 2.2 Guard `resolveKey`, every copy button, edit, clone, print and QR for flagged items, and add the checkbox to the create and edit dialogs and a lock marker to `SecretListItem.vue`. Verify: a vitest that enumerates the reveal paths of `SecretDetailSidebar.vue` and `SecretListItem.vue`, and a Playwright flow reveal a flagged item with a wrong and then the right master password. Built: the value field, every copy button, the card and BSN reveals and edit in the sidebar, the list row's copy button, the lock marker, and the checkbox in both dialogs; the enumeration test is in `tests/vitest/reprompt.spec.js`. The TOTP code displays (the totp item and a login's own code) now wait behind the guard too: `TotpDisplay` computes no code until the guard lets it through, hands the guard to its copy button, and asks again for another seed or after the vault locks (`tests/components/TotpDisplay.reprompt.spec.js`, and the enumeration in `reprompt.spec.js`). Left: the Playwright flow, which needs a live instance. The app has no clone, print or QR action today.

## 3. Browser extension

- [x] 3.1 Ask for and verify the master password in the popup before `doFill()` of a flagged item. Verify: extension unit test that a flagged fill without the password fills nothing. Built: the background's `fill()` (`browser-extension/src/autofill/fill.ts`) answers a flagged item with `needsReprompt` until the popup's `fill.request` carries a master password that `verifyMasterPassword()` (`src/vault/unlock.ts`) checks against the cached suite envelope, on every fill; a wrong one answers `wrongMasterPassword` and fills nothing. The popup's `useFill` shows `RepromptDialog.tsx` and carries the password only to the end of that one fill. The inline list, the context menu, the shortcut and page load cannot ask, so they fill nothing for a flagged item (also the inline code fill). Tests: `src/autofill/fill.test.ts`, `src/autofill/code-fill.test.ts`, `entrypoints/popup/views/VaultList.test.tsx`.

## 4. Docs

- [x] 4.1 Document the flag and what it does and does not protect against. Verify: docs build. Page written at `docs/master-password-reprompt.md`, with the extension's behaviour; `docusaurus build` succeeds (installed without the lockfile, which is out of sync with `docs/package.json` on development).

## Acceptance criteria

- A flagged item cannot be shown, copied, edited, cloned, printed, shown as a QR code or filled without entering the master password again.
- Each of those actions asks again; there is no window.
- The master password is never sent to the server.
