# Tasks: ask for the master password again before a sensitive item is shown or filled

## 1. Backend

- [x] 1.1 Add the `reprompt` column and entity field, accept it on create and update, return it in `jsonSerialize()`, and copy it onto new share copies; bump `<version>`. Verify: PHPUnit for create, update and a new share copy. Migration `Version001021Date20261114130000`; create takes it only when true, update through `SecretUpdateController` as a boolean; `RecipientSecretCopyFactory` copies it onto new copies. Tests in `tests/Unit/Service/SecretRepromptTest.php` and `RecipientSecretCopyCreateTest`.

## 2. Web app

- [x] 2.1 Add `RepromptDialog.vue` and the `useReprompt()` guard on top of `verifyMasterPassword()`. Verify: vitest for accept, reject and cancel. The prompt is an app-wide store (`repromptPrompt.js`) with one dialog in the app shell, like the key-proof prompt; `tests/vitest/reprompt.spec.js`.
- [ ] 2.2 Guard `resolveKey`, every copy button, edit, clone, print and QR for flagged items, and add the checkbox to the create and edit dialogs and a lock marker to `SecretListItem.vue`. Verify: a vitest that enumerates the reveal paths of `SecretDetailSidebar.vue` and `SecretListItem.vue`, and a Playwright flow reveal a flagged item with a wrong and then the right master password. Built: the value field, every copy button, the card and BSN reveals and edit in the sidebar, the list row's copy button, the lock marker, and the checkbox in both dialogs; the enumeration test is in `tests/vitest/reprompt.spec.js`. The TOTP code displays (the totp item and a login's own code) now wait behind the guard too: `TotpDisplay` computes no code until the guard lets it through, hands the guard to its copy button, and asks again for another seed or after the vault locks (`tests/components/TotpDisplay.reprompt.spec.js`, and the enumeration in `reprompt.spec.js`). Left: the Playwright flow, which needs a live instance. The app has no clone, print or QR action today.

## 3. Browser extension

- [ ] 3.1 Ask for and verify the master password in the popup before `doFill()` of a flagged item. Verify: extension unit test that a flagged fill without the password fills nothing. Not built: the extension is a separate WXT project; until this lands a flagged item is protected in the web app only, which the docs say.

## 4. Docs

- [ ] 4.1 Document the flag and what it does and does not protect against. Verify: docs build. Page written at `docs/master-password-reprompt.md`; the docs build was not run.

## Acceptance criteria

- A flagged item cannot be shown, copied, edited, cloned, printed, shown as a QR code or filled without entering the master password again.
- Each of those actions asks again; there is no window.
- The master password is never sent to the server.
