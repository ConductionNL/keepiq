## 1. Messages and setting

- [ ] 1.1 Extend `src/messages.ts` per design.md § Message contract: `code` on `FillExecute`, `FillExecuteReply`, `FillResult` and `InlinePickReply`; `mode` on `InlineOpen` and `InlineStateReply`; `InlineCode` on `InlineRow`
  - Every listener keeps the `unknown` cast; an `inline.open` without a valid `mode` reads as `login`
  - Typecheck is clean
- [ ] 1.2 Add `autoCopyTotp` (boolean, default `true`) to `AccountSettings` with its validator, and the toggle "Copy TOTP automatically" with its help text below Clear clipboard in `views/settings/Autofill.tsx`, with a test
  - Texts in `locales/en.yml` and `locales/nl.yml`; `locales.test.ts` passes
  - Both targets build

## 2. Pairing and codes

- [ ] 2.1 Let `candidatesFor()` in `src/autofill/matcher.ts` take the type name, `'login'` by default, and write `src/autofill/totp-pair.ts` with `matchingTotp()`, `pairedTotp()` and `currentCode()`, with tests
  - Tests: the imported pair, the empty-login fallback, two accounts on one site, two empty-login candidates (nothing), another site's item, a use-only and a blocked row (never), an invalid seed (paired, no code), a username differing only in case and spaces
  - The toolbar badge's count is unchanged (`src/toolbar-icon.ts` tests pass)

## 3. Fill

- [ ] 3.1 In `src/autofill/fill.ts`: `FillTarget.code`, pairing against the first frame that gets the login, `code` in each `fill.execute` whose frame the paired row matches, "filled something" as `filled !== 'none' || code` for last used, `rememberItem()` and the result; `fillOnPageLoad()` passes `code: false`
  - Seed and code are never written to any storage; a test reads both areas after a fill
  - A subframe on another base domain gets `code: null`
- [ ] 3.2 After a fill that filled something, with `autoCopyTotp` on and not on page load, copy the paired code with `writeFromBackground(code, clearClipboardMs, { codeOf: accountId })`
  - Tests: copy on popup, menu, shortcut and inline fills; none with the setting off, on page load, with nothing filled, without a pair and with an invalid seed
- [ ] 3.3 In `src/autofill/fields.ts` and `src/autofill/execute.ts`: recovery and backup keywords (English and Dutch) drop the `oneTimeCode` role; `codeGroup()` for single and split fields; `executeFill()` fills the anchored, focused or first empty code group, one character per input when the lengths agree, skips a filled group and drops the code otherwise
  - Tests: `test-site/otp.html`'s field, a six-input split group, a split group of five with a six-digit code (whole code in the first input), a "Recovery code" field with `one-time-code`, a filled code field, a login form with a code field
  - Both targets build
- [ ] 3.4 Popup: `useFill` treats `code: true` as success, so a second-step page closes the popup instead of showing "Unable to autofill on this page", with a test
  - Both targets build

## 4. Inline list in code mode

- [ ] 4.1 `isQualifying()` returns the mode; `inline-host.ts` sends it with `inline.open`; `inline-session.ts` stores it, counts `matchingTotp()` for the height in code mode, and answers `inline.state` with code rows and `mode`
  - Locked: one row of height and `{ state: 'locked', rows: [] }`, no decryption
  - Tests for both modes, and a refresh `inline.state` from the bound list frame
- [ ] 4.2 Write `src/autofill/code-fill.ts` with `pickCode()`: the login pick's checks, the insecure question, a code computed at the pick, one `fill.execute` with `anchorKey` to the list's frame, last used, interaction, close with refocus; no copy
  - Tests: stale item, locked, `not_visible`, insecure, success
- [ ] 4.3 `entrypoints/inline-list/list.ts`: code rows with the code in two halves and the seconds left, both `aria-hidden`; the invalid-seed row disabled with "Not a valid authenticator secret"; one timer to the earliest `validUntil` that re-sends `inline.state`; the code-mode frame title, listbox name, no-match, locked and status texts
  - Texts in both catalogs; WCAG 2.2 AA as the login list
  - Both targets build

## 5. Context menu and clipboard

- [ ] 5.1 Add Copy verification code to `src/menus.ts` after Copy password, from `matchingTotp()`, and its click: re-check the match, copy the current code with `codeOf`
  - Tests: one item, several (submenu, at most 10), none (disabled), use-only (not listed), locked (Unlock only)
  - Both targets build
- [ ] 5.2 In `src/clipboard.ts`: `clipboardCode` in `sessionValues` set by a write with `codeOf`, removed by any other copy, `clipboard.copied` and the clear alarm; on `vault.locked` for that account, clear the clipboard and the alarm at once
  - Tests: lock before the delay, delay Never, another copy first, locking another account, logout
  - Both targets build

## 6. Test site, docs and verification

- [ ] 6.1 Add `test-site/otp-split.html` (six one-character inputs), `test-site/login-otp.html` (username, password and code in one form) and `test-site/recovery-code.html` (a "Recovery code" field with `one-time-code`), linked from `test-site/index.html`
- [ ] 6.2 Add a Playwright spec to `e2e/` for the code list on `test-site/otp.html`: opens in code mode, shows a seeded item's code, a pick fills it, and a locked vault shows no code
  - `npm run test:e2e` passes
- [ ] 6.3 Update `ARCHITECTURE.md` (modules, storage, autofill flow), `SECURITY.md` (Where data lives, Locking, Clipboard) and ADR-002's storage table with `clipboardCode` and the codes in the list page, one line each
- [ ] 6.4 Walk every scenario in the three delta specs on Chrome (`npm run dev`) and Firefox (`npm run dev:firefox`) against the test site and a vault with an imported login and its `totp` item
  - Every scenario holds on both browsers
  - `npm test`, typecheck and lint are clean
  - Both targets build
