## Context

By the time this change lands, the earlier changes provide:
- `src/totp/totp.ts`: `parseTotpSeed()`, `generateCode()`, `secondsRemaining()`, a port of the web app's module, pure and WebCrypto only (ext-vault-browse);
- the fill pipeline `fill()` in `src/autofill/fill.ts`, the inline list and its session, the context menu, `classifyField()` with its `oneTimeCode` role, and `writeFromBackground()` with `scheduleClipboardClear()` (ext-autofill);
- per-account settings with `clearClipboardMs` and the Autofill view (ext-settings);
- `TotpCode`, the popup's code with countdown and copy (ext-vault-browse).

Since keepiq#1325 a secret has an encrypted `totp` field next to `key`, `login` and `additionalFields`: null or `''` for no value, else ciphertext of an `otpauth://totp/` URI or a base32 secret. The web app stores a login's Authenticator key there, and its imports put a Bitwarden or KeePass seed there instead of in a `(TOTP)` item (keepiq#1324). The list routes send it with the row, so the snapshot holds it as it holds `key`.

## Goals / Non-Goals

**Goals:**
- After a user-picked fill, the login's code is on the clipboard and in the code field, as in Bitwarden.
- The inline list and the context menu reach the code of any matching login.
- The detail view shows a login's code.
- Nothing about TOTP is readable once the vault locks.

**Non-Goals:**
- Standalone `totp` items in autofill: no client creates them any more.
- Editing a login's seed, QR scanning, HOTP.
- Fill or copy on page load.

## Decisions

- **The seed is the login's own `totp` field, nothing else.** No pairing with a standalone `totp` item: it would guess, and keepiq#1324 ended those items. A row without the field, or with null or `''`, has no code.
- **No version gate.** The field is in 0.3.12, the minimum, so every supported server has it.
- **One `fill.execute`, the code inside.** The background adds `code` to the message of each frame it already sends the login to; the content script fills it only into a code group and drops it otherwise. Every such frame matches the login, so each may get its code. The message reaches the content script's isolated world, which the page cannot read. Page load passes no code: `FillTarget` gains `code: boolean`, `false` in `fillOnPageLoad()`.
- **The code is computed after the frames are known and before the send,** from the seed decrypted in the same handler as `login` and `key`, and the strings go out of scope when `fill()` returns, as ADR-002's "per fill".
- **Copy after the fill, from the background, for every surface.** `fill()` copies when the combined outcome filled something, `autoCopyTotp` is on and the target is not page load, with `writeFromBackground(code, clearMs, { codeOf })`. The code is computed again for the copy, so a fill that crossed into the next period copies the one that works now. The popup does not copy itself: it closes on a successful fill.
- **`FillOutcome` stays; `FillExecuteReply` and `FillResult` gain `code: boolean`.** A frame that filled only a code reports `filled: 'none', code: true`. The popup's "Unable to autofill" toast, last used, the copy and `rememberItem()` all use "filled something" = `filled !== 'none' || code`.
- **Code mode in the inline list is a session field, not a second list.** `inline.open` carries `mode: 'login' | 'code'` from `isQualifying()`. The session stores it; `inline.state` and `inline.pick` branch on it. The host, key hand-off, position, keyboard relay and visibility guard are unchanged.
- **Code rows carry a code with its end, the list page re-asks.** `inline.state` in code mode decrypts each row's `totp`, computes the code and replies `{ digits, period, validUntil }`, `digits: null` for a seed that does not parse. The list page sets one interval for the countdowns and asks `inline.state` again once the earliest valid code ends. Seeds never go to the list page: it is embedded in a hostile site, and a seed outlives a code.
- **The pick computes a fresh code.** `pickCode()` in `src/autofill/code-fill.ts` sends `fill.execute { username: null, password: null, code, origin, anchorKey }` after the login pick's checks, so a code shown at 29 seconds is never filled at 31.
- **Code groups are found next to the field.** `codeGroup(field)` in `src/autofill/fields.ts`: the run of adjacent short inputs around it inside its group (four to eight of one character, or two to four of two to four characters with a one-time-code member), else the field alone when it is a one-time code. The one-time-code member keeps a birth date in three inputs from reading as a code. Recovery and backup keywords (`recovery`, `backup`, `herstel`, `back-up`) in label, name, id or placeholder remove the `oneTimeCode` role.
- **A list opened by focus is dropped once its field filled.** A split code's fill moves the page's focus box by box, and the reply to that focus's `inline.open` comes after the box was filled.
- **Context menu.** `menuEntries()` adds `perItem('copy-code', …)` after Copy password, from `matchingCodes()`. The click re-checks the match like `copy()` does, then copies the code.
- **Detail view.** `totp` is a `DecryptField`; `ItemDetail` renders `TotpCode` with it in the login's section. A use-only copy opens it too (`USE_ONLY_FIELDS`), since `use-only-shares` lets a client show its code; `TotpCode` never renders the seed.
- **Clear on lock.** `writeFromBackground()` gains an optional `{ codeOf: accountId }`. With it, it stores `clipboardCode = accountId` after the write; any other copy, `scheduleClipboardClear()` for the popup's `clipboard.copied` and the `clipboard-clear` alarm remove it. On `vault.locked`, `clearCodeAfterLock()` clears the clipboard and the alarm when that account is no longer unlocked. `clipboard.ts` keeps it in `storage.session` itself, so the popup and offscreen bundles stay free of the key store.
- **Setting key `autoCopyTotp`,** boolean, default `true`, in `AccountSettings` with its validator; a toggle below Clear clipboard.
- **Blocked rows drop `totp` and `passkeys`,** as they drop the other value fields in `withheld()`.

## Lock audit

What someone at the browser without the master password can reach through each new surface or payload, with the vault locked:

| Surface or payload | While locked |
| --- | --- |
| Inline list, code mode | Locked state only; `inline.open` height is one row, `inline.state` is `{ state: 'locked', rows: [] }` |
| Inline list open at the lock | Closed by the `vault.locked` broadcast; a late `inline.state` refresh gets `locked` or `null` |
| `inline.pick` in code mode | `refused: 'locked'`, nothing decrypted |
| `fill.execute` | Never sent: `fill()` needs `activeUnlockedId()` and `getKey()` |
| Context menu | "Unlock vault" only |
| Shortcut | Opens the popup |
| Detail view | Not reachable; the popup shows the unlock view |
| Clipboard | A code the extension copied is cleared at the lock |
| `storage.local`, `storage.session` | `totp` only as ciphertext in the snapshot; `clipboardCode` holds an account id, the inline session item ids |

## Storage

| Datum | Lives in | Cleared by |
| --- | --- | --- |
| `totp` ciphertext of each row | `vaultCache.<id>` in `storage.local` | As the snapshot |
| `autoCopyTotp` in `settings.<id>` | `storage.local` | Account removal |
| `mode` in `inlineSession.<tabId>` | `storage.session`; background memory on Firefox below 115 | As the session |
| `clipboardCode`: the account id whose code is on the clipboard | `storage.session`; background memory on Firefox below 115 | The clear alarm, any other copy, lock or logout of that account, restart |
| Seed and code | Background memory, for one fill, `inline.state` or menu click; popup memory while the detail view shows it | The end of that handler; the view closing |
| Codes shown in the code list | List page memory | The list closing |
| A filled code | The page's field | The page |

## Module layout

- `src/autofill/login-codes.ts` (new): `matchingCodes(accountId, pageUrl)`, `currentCode(seed, now)`, `inlineCode(row, key, now)`, `codeOf(row, key)`.
- `src/autofill/code-fill.ts` (new): `pickCode()` for the code list.
- `src/autofill/fill.ts`: `FillTarget.code`; the code in `fill.execute`, the copy, "filled something".
- `src/autofill/triggers.ts`: `fillOnPageLoad()` passes `code: false`.
- `src/autofill/fields.ts`: recovery keywords, `codeGroup()`, `findCodeGroups()`, `isQualifying()` returning the mode.
- `src/autofill/execute.ts`: fill the code group, split by the inputs' lengths, drop the code otherwise.
- `src/autofill/inline-session.ts`, `src/autofill/inline-host.ts`: `mode` on open and in the session; code rows; code pick.
- `entrypoints/inline-list/list.ts`: code rows, the countdown and refresh, the code-mode texts.
- `src/menus.ts`: Copy verification code.
- `src/clipboard.ts`: `codeOf`, `clipboardCode`, `clearCodeAfterLock()`, registered on `vault.locked` in `entrypoints/background.ts`.
- `src/api/types.ts`, `src/vault/sync.ts`: the fields on a row, dropped from blocked rows.
- `src/messages.ts`, `src/background/requests.ts`, `entrypoints/popup/views/ItemDetail.tsx`: `totp` as a decrypt field and the login's code.
- `src/accounts/settings.ts`, `entrypoints/popup/views/settings/Autofill.tsx`: `autoCopyTotp`.
- `locales/en.yml`, `locales/nl.yml`: every new text.
- `test-site/`: `otp-split.html`, `otp-halves.html`, `login-otp.html`, `recovery-code.html`, `totp-login.html`, `totp-setup.html` with `totp.js` and `totp-qr.svg`, linked from `test-site/index.html`.

## Message contract

```ts
/** `code`: the filled or picked login's current code, `null` on page load or without one. */
type FillExecute = { kind: 'fill.execute'; username: string | null; password: string | null; code: string | null; origin: string; anchorKey?: string }
type FillExecuteReply = { filled: FillOutcome; code: boolean }
type FillResult = { filled: FillOutcome; code: boolean; needsInsecureConfirm?: boolean }

type InlineMode = 'login' | 'code'
type InlineOpen = { kind: 'inline.open'; mode: InlineMode }
/** `null` digits: not a valid seed. `validUntil`: epoch ms the code ends. */
type InlineCode = { digits: string | null; period: number; validUntil: number }
type InlineRow = { id: string; name: string; username: string | null; code?: InlineCode }
type InlineStateReply = { state: 'locked' | 'unlocked'; mode: InlineMode; host: string; rows: InlineRow[]; theme: Theme } | null
type InlinePickReply = { filled: FillOutcome; code: boolean } | { needsInsecureConfirm: true } | { refused: 'locked' | 'not_visible' | 'stale' } | null

type DecryptField = 'login' | 'key' | 'additionalFields' | 'totp'
```

No new kind. An `inline.open` without a valid `mode` is read as `login`.

## Risks / Trade-offs

- [A site on the same base domain as the login gets its code] → The same exposure as its password under the Base domain rule; the user can pick Host or Exact.
- [A page fakes a code field to receive the code] → It only gets the code of a login matching its own URL, after the user picked it there; a site's own code is worthless to it, since it holds the seed.
- [Existing standalone `totp` items get no autofill] → Accepted: no client creates them since keepiq#1324, the app has no users beyond internal testers, and the popup still shows their code.
- [The code list opens on every SMS or e-mail code field, also for users without codes] → Kept: it opening regardless is what hides from the page whether a code matches (ext-autofill).
- [Clock skew makes codes fail] → As in every authenticator app (ext-vault-browse).
- [A browser restart drops `clipboardCode` while the OS clipboard keeps the code] → Accepted; the code expires within its period, and a restart also loses the clear alarm for every other copy.
- [The clear on lock overwrites whatever the user copied since] → Only while `clipboardCode` is set, which any copy through the extension removes; a copy outside it is not seen, as with every clear (SECURITY.md § Clipboard).

## Open Questions

- Should a pick from the code list also copy the code? Provisional: no; Bitwarden's behaviour here is unverified.
- Should the lock clear every pending copy, not only a code? Provisional: only a code; the rest would change ext-autofill's and ext-vault-browse's copies.
