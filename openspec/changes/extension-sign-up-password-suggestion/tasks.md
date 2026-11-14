# Tasks: suggest a strong password in a sign-up field

## 1. Offer

- [x] 1.1 Show "Use a strong password" in the inline list (`browser-extension/entrypoints/inline-list/`) when a field of a `signUp` or `passwordChange` group that is not the current-password field gets focus (`browser-extension/src/autofill/fields.ts`). Verify: vitest on the field roles, including a change-password form with a current-password field.
- [x] 1.2 On a trusted click, generate under the policy (`browser-extension/src/generator/policy.ts`) in the worker and fill the new-password field and its confirmation through `browser-extension/src/autofill/execute.ts`; ignore an untrusted click. Verify: vitest on the fill and on a script-dispatched click.

## 2. Check

- [ ] 2.1 Walk the scenarios of `specs/extension-generator` in Chrome (`npm run dev`) and Firefox (`npm run dev:firefox`) on a sign-up form and a change-password form.

## Acceptance criteria

- A sign-up form gets a generated password in both fields after one click.
- A current-password field never gets the offer.
