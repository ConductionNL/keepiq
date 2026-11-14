## 1. Messages, rows and setting

- [x] 1.1 Extend `src/messages.ts` per design.md § Message contract: `code` on `FillExecute`, `FillExecuteReply`, `FillResult` and `InlinePickReply`; `mode` on `InlineOpen` and `InlineStateReply`; `InlineCode` on `InlineRow`; `totp` in `DecryptField` and `USE_ONLY_FIELDS`, `FormField` for what the edit form saves
  - Every listener keeps the `unknown` cast; an `inline.open` without a valid `mode` reads as `login`
  - Typecheck is clean
- [x] 1.2 Add `totp` and `passkeys` to `OpenSecretRow`, and drop both from blocked rows in `withheld()` in `src/vault/sync.ts`, with a test
- [x] 1.3 Add `autoCopyTotp` (boolean, default `true`) to `AccountSettings` with its validator, and the toggle "Copy TOTP automatically" with its help text below Clear clipboard in `views/settings/Autofill.tsx`, with a test
  - Texts in `locales/en.yml` and `locales/nl.yml`; `locales.test.ts` passes
  - Both targets build

## 2. Codes

- [x] 2.1 Write `src/autofill/login-codes.ts` with `hasCode()`, `matchingCodes()`, `currentCode()`, `seedOf()` and `inlineCode()`, with tests
  - Tests: logins with a code in last-used order, use-only copies included; null, `''` and a missing field as no code; another site's login, a blocked row and a standalone `totp` item never listed; an invalid seed and unreadable ciphertext give no digits

## 3. Fill

- [x] 3.1 In `src/autofill/fill.ts`: `FillTarget.code`, the login's own code in each `fill.execute`, "filled something" as `filled !== 'none' || code` for last used, `rememberItem()` and the result; `fillOnPageLoad()` passes `code: false`
  - Seed and code are never written to any storage; a test reads both areas after a fill
  - A standalone `totp` item for the same site and username lends no code
- [x] 3.2 After a fill that filled something, with `autoCopyTotp` on and not on page load, copy the code with `writeFromBackground(code, clearClipboardMs, { codeOf: accountId })`
  - Tests: copy on popup, menu, shortcut and inline fills; none with the setting off, on page load, with nothing filled, without a code and with an invalid seed
- [x] 3.3 In `src/autofill/fields.ts` and `src/autofill/execute.ts`: recovery and backup keywords (English and Dutch) drop the `oneTimeCode` role; `codeGroup()` for single and split fields; `executeFill()` fills the anchored, focused or first empty code group, split by the inputs' lengths when they agree, skips a filled group and drops the code otherwise
  - Tests: `test-site/otp.html`'s field, a six-input split group, two halves of three, a birth date in three inputs (no group), a split group of five with a six-digit code (whole code in the first input), a "Recovery code" field with `one-time-code`, a filled code field, a login form with a code field
  - Both targets build
- [x] 3.4 Popup: `useFill` treats `code: true` as success, so a second-step page closes the popup instead of showing "Unable to autofill on this page", with a test
  - Both targets build

## 4. Inline list in code mode

- [x] 4.1 `isQualifying()` returns the mode; `inline-host.ts` sends it with `inline.open` and drops a list opened by focus once its field filled; `inline-session.ts` stores it, counts `matchingCodes()` for the height in code mode, and answers `inline.state` with code rows and `mode`
  - Locked: one row of height and `{ state: 'locked', rows: [] }`, no decryption
  - Tests for both modes, and a refresh `inline.state` from the bound list frame
- [x] 4.2 Write `src/autofill/code-fill.ts` with `pickCode()`: a login with a code still matching the frame, the insecure question, a code computed at the pick, one `fill.execute` without username or password, with `anchorKey`, to the list's frame, last used; no copy
  - Tests: no longer matching, no code, invalid seed, standalone item, locked, insecure, use-only, success
- [x] 4.3 `entrypoints/inline-list/list.ts`: code rows with the code in two halves and the seconds left, both `aria-hidden`; the invalid-seed row disabled with "Not a valid authenticator secret"; one interval for the countdowns that re-sends `inline.state` once the earliest valid code ends; the code-mode frame title, listbox name, no-match, locked and status texts
  - Texts in both catalogs; WCAG 2.2 AA as the login list
  - Both targets build

## 5. Context menu, clipboard and detail view

- [x] 5.1 Add Copy verification code to `src/menus.ts` after Copy password, from `matchingCodes()`, and its click: re-check the match, copy the current code with `codeOf`
  - Tests: one login, several (submenu, at most 10), use-only included, none (disabled), no code, blocked and standalone items (not listed), locked (Unlock only)
  - Both targets build
- [x] 5.2 In `src/clipboard.ts`: `clipboardCode` set by a write with `codeOf`, removed by any other copy, `clipboard.copied` and the clear alarm; on `vault.locked`, clear the clipboard and the alarm at once when that account is no longer unlocked
  - Tests: lock before the delay, delay Never, another copy first, locking another account, Firefox below 115
  - Both targets build
- [x] 5.3 Detail view: `ItemDetail` asks `item.decrypt` for `totp` and shows `TotpCode` under Login credentials, for a use-only copy too; `decrypt()` reads `''` as no value
  - Tests: a login's code without its seed, no row without a code, a use-only copy's code, the background opening `totp` for a use-only copy and skipping `''`

## 6. Test site, docs and verification

- [x] 6.1 Add `test-site/otp-split.html` (six one-character inputs), `test-site/otp-halves.html` (two inputs of three with a dash), `test-site/login-otp.html` (username, password and code in one form), `test-site/recovery-code.html` (a "Recovery code" field with `one-time-code`), `test-site/totp-login.html` (a login whose submit leads to the code step in the layout picked) and `test-site/totp-setup.html` (a QR code and the test key), linked from `test-site/index.html`
  - The code pages check the code against the test key, so a wrong fill shows
- [x] 6.2 Add a Playwright spec to `e2e/` for the code list: each layout filled with a code the page accepts, the login flow into each code step, a login pick filling a code on the same form, a recovery field without a list, and a locked vault showing no code
  - `npm run test:e2e` passes
- [x] 6.3 Update `ARCHITECTURE.md` (modules, storage, autofill flow), `SECURITY.md` (Where data lives, Locking, Clipboard) and ADR-002's storage table with `clipboardCode` and the codes, one line each
- [ ] 6.4 Walk every scenario in the three delta specs on Chrome (`npm run dev`) and Firefox (`npm run dev:firefox`) against the test site and a vault with a login whose Authenticator key is the test key
  - Every scenario holds on both browsers
  - `npm test`, typecheck and lint are clean
  - Both targets build
