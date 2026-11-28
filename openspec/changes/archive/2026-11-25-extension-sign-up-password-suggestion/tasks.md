# Tasks: suggest a strong password in a sign-up field

## 1. Offer

- [x] 1.1 Show "Use a strong password" in the inline list (`browser-extension/entrypoints/inline-list/`) when a field of a `signUp` or `passwordChange` group that is not the current-password field gets focus (`browser-extension/src/autofill/fields.ts`). Verify: vitest on the field roles, including a change-password form with a current-password field.
- [x] 1.2 On a trusted click, generate under the policy (`browser-extension/src/generator/policy.ts`) in the worker and fill the new-password field and its confirmation through `browser-extension/src/autofill/execute.ts`; ignore an untrusted click. Verify: vitest on the fill and on a script-dispatched click.

## 2. Check

- [x] 2.1 Walk the scenarios of `specs/extension-generator` in Chrome (`npm run dev`) and Firefox (`npm run dev:firefox`) on a sign-up form and a change-password form. Done 25 Nov 2026: Chromium (Playwright 1.64, built chrome-mv3) runs `browser-extension/e2e/password-suggest.spec.ts` on `test-site/signup.html` and `change-password.html`, 4/4 pass; Firefox 161 (built firefox-mv2 as a temporary add-on over geckodriver 0.36, real WebDriver pointer clicks, the e2e fake unlocked vault) walked the same scenarios, 7/7 checks pass: the offer shows on the new-password field, a page-dispatched click fills nothing, one click fills both fields with the same 14-character password, email, username and current password stay empty, the current-password field gets no offer.

## Acceptance criteria

- A sign-up form gets a generated password in both fields after one click.
- A current-password field never gets the offer.
