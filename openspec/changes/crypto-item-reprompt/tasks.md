# Tasks: ask for the master password again before a sensitive item is shown or filled

## 1. Backend

- [ ] 1.1 Add the `reprompt` column and entity field, accept it on create and update, return it in `jsonSerialize()`, and copy it onto new share copies; bump `<version>`. Verify: PHPUnit for create, update and a new share copy.

## 2. Web app

- [ ] 2.1 Add `RepromptDialog.vue` and the `useReprompt()` guard on top of `verifyMasterPassword()`. Verify: vitest for accept, reject and cancel.
- [ ] 2.2 Guard `resolveKey`, every copy button, edit, clone, print and QR for flagged items, and add the checkbox to the create and edit dialogs and a lock marker to `SecretListItem.vue`. Verify: a vitest that enumerates the reveal paths of `SecretDetailSidebar.vue` and `SecretListItem.vue`, and a Playwright flow reveal a flagged item with a wrong and then the right master password.

## 3. Browser extension

- [ ] 3.1 Ask for and verify the master password in the popup before `doFill()` of a flagged item. Verify: extension unit test that a flagged fill without the password fills nothing.

## 4. Docs

- [ ] 4.1 Document the flag and what it does and does not protect against. Verify: docs build.

## Acceptance criteria

- A flagged item cannot be shown, copied, edited, cloned, printed, shown as a QR code or filled without entering the master password again.
- Each of those actions asks again; there is no window.
- The master password is never sent to the server.
