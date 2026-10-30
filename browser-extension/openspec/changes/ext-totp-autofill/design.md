## Context

By the time this change lands, the earlier changes provide:
- `src/totp/totp.ts`: `parseTotpSeed()`, `generateCode()`, `secondsRemaining()`, a port of the web app's module, pure and WebCrypto only (ext-vault-browse);
- the `totp` system type, whose `key` holds an `otpauth://totp/` URI or a bare base32 seed; it also carries a `url` and may carry a `login` (ADR-003, Keepiq `secrets`);
- the fill pipeline `fill()` in `src/autofill/fill.ts`, the inline list and its session, the context menu, `classifyField()` with its `oneTimeCode` role, and `writeFromBackground()` with `scheduleClipboardClear()` (ext-autofill);
- per-account settings with `clearClipboardMs` and the Autofill view (ext-settings).

Keepiq's importers split a Bitwarden or KeePass login's seed into a `totp` item named "<name> (TOTP)" with the login's `url` and `login` (`expandTotpRows` in the web app's import store), so most pairs share both.

## Goals / Non-Goals

**Goals:**
- After a user-picked fill, the paired code is on the clipboard and in the code field, as in Bitwarden.
- The inline list and the context menu reach any matching authenticator item, also when pairing is ambiguous.
- Nothing about TOTP is readable once the vault locks.

**Non-Goals:**
- A link between a login and its `totp` item stored anywhere.
- TOTP on the login's card or detail view, QR scanning, HOTP.
- Fill or copy on page load.

## Decisions

- **Pair by URL and username, never guess.** `pairedTotp(vault, rule, frameUrl, username, key)` in `src/autofill/totp-pair.ts` takes the `totp` rows matching `frameUrl` (blocked and use-only left out), decrypts each one's `login`, keeps those equal to `username` (trimmed, `toLowerCase()`), else those with an empty `login`, and returns the row only when exactly one is left. Matching by URL alone would give `bob`'s code to `alice` on a shared site; trying the next candidate after an invalid seed would too.
- **Pair against the first frame that gets the login.** That is the top frame when it matches, else the first matching subframe. Each frame then gets the code only when the paired row also matches it, so a subframe on another base domain never receives one.
- **One `fill.execute`, the code inside.** The background adds `code` to the message of each frame it already sends the login to; the content script fills it only into a code group and drops it otherwise. The message reaches the content script's isolated world, which the page cannot read, so a second round trip asking for a code field first would expose nothing less. Page load passes no code: `FillTarget` gains `code: boolean`, `false` in `fillOnPageLoad()`.
- **The code is computed after the frames are known and before the send,** from the seed decrypted in the same handler as `login` and `key`, and the strings go out of scope when `fill()` returns, as ADR-002's "per fill".
- **Copy after the fill, from the background, for every surface.** `fill()` copies when the combined outcome filled something, `autoCopyTotp` is on and the target is not page load, with `writeFromBackground(code, clearMs)`. The popup does not copy itself: it closes on a successful fill, and one path keeps the clear-on-lock bookkeeping in one place.
- **`FillOutcome` stays; `FillExecuteReply` and `FillResult` gain `code: boolean`.** A frame that filled only a code reports `filled: 'none', code: true`. The popup's "Unable to autofill" toast, last used, the copy and `rememberItem()` all use "filled something" = `filled !== 'none' || code`.
- **Code mode in the inline list is a session field, not a second list.** `inline.open` carries `mode: 'login' | 'code'` from `isQualifying()`. The session stores it; `inline.state` and `inline.pick` branch on it. The host, key hand-off, position, keyboard relay and visibility guard are unchanged, so code mode inherits every protection of ext-autofill § Inline list.
- **Code rows carry a code with its end, the list page re-asks.** `inline.state` in code mode decrypts each row's seed, computes the code and replies `{ digits, period, validUntil }` or `null` for an invalid seed. The list page sets one timer to the earliest `validUntil` and sends `inline.state` again; the background accepts it from the bound list frame as today. Sending seeds to the list page was rejected: the page embeds it in a hostile site, and a seed outlives a code.
- **The pick computes a fresh code.** `pickCode()` in `src/autofill/code-fill.ts` runs the login pick's checks (session, offered, still matching, unlocked, `inline.verify`, insecure question), then sends `fill.execute { username: null, password: null, code, origin, anchorKey }`. A code shown at 29 seconds is never filled at 31.
- **Split groups are found next to the field.** `codeGroup(field)` in `src/autofill/fields.ts`: the field alone when it is a one-time code with a `maxlength` other than 1, else the run of adjacent visible, enabled one-character text, `tel` or `number` inputs around it inside its group, when there are four to eight. Recovery and backup keywords (`recovery`, `backup`, `herstel`, `back-up`) in label, name, id or placeholder remove the `oneTimeCode` role before grouping.
- **Context menu.** `menuEntries()` adds `perItem('copy-code', i18n.t('contextMenu.copyCode'), totpRows)` after Copy password, from `matchingTotp(accountId, pageUrl)` beside `matchingLogins()`. The click re-checks the match like `copy()` does, then copies the code.
- **Clear on lock.** `writeFromBackground()` gains an optional `{ codeOf: accountId }`. With it, it stores `clipboardCode = accountId` in `sessionValues` after the write; without it, and in `scheduleClipboardClear()` for the popup's `clipboard.copied`, it removes the key. The `clipboard-clear` alarm removes it too. A `vault.locked` broadcast for that account, or for every account, runs `clearClipboard()`, clears the alarm and removes the key. The id is kept so locking another account does not wipe this one's copy.
- **Setting key `autoCopyTotp`,** boolean, default `true`, in `AccountSettings` with its validator. The Autofill view renders it as a toggle below Clear clipboard through `account.settings.set`; no new message.
- **Use-only `totp` copies are left out everywhere.** The extension never shows or copies a use-only value, and Keepiq's `use-only-shares` lets it fill one only into a password field.
- **No Keepiq request.** Everything reads the snapshot; `totp` is a system type since 0.1.4, below `MIN_KEEPIQ_VERSION`, so no `KEEPIQ_FEATURES` entry and no version check.

## Lock audit

What someone at the browser without the master password can reach through each new surface or payload, with the vault locked:

| Surface or payload | While locked |
| --- | --- |
| Inline list, code mode | Locked state only; `inline.open` height is one row, `inline.state` is `{ state: 'locked', rows: [] }` |
| Inline list open at the lock | Closed by the `vault.locked` broadcast; a late `inline.state` refresh gets `locked` or `null` |
| `inline.pick` in code mode | `refused: 'locked'`, nothing decrypted |
| `fill.execute` | Never sent: `fill()` needs `activeUnlockedId()` and `getKey()` |
| `fill.request` reply | `{ filled: 'none', code: false }` |
| Context menu | "Unlock vault" only |
| Shortcut | Opens the popup |
| Clipboard | A code the extension copied is cleared at the lock |
| `storage.local`, `storage.session` | No seed or code is ever written; `clipboardCode` holds an account id, the inline session item ids |

## Storage

| Datum | Lives in | Cleared by |
| --- | --- | --- |
| `autoCopyTotp` in `settings.<id>` | `storage.local` | Account removal |
| `mode` in `inlineSession.<tabId>`, with the offered `totp` item ids | `storage.session`; background memory on Firefox below 115 | As the session: list close, next list, tab close or load, lock, logout, account switch, restart |
| `clipboardCode`: the account id whose code is on the clipboard | `storage.session`; background memory on Firefox below 115 | The clear alarm, any other copy, lock or logout of that account, restart |
| Last-used time of a `totp` item picked from the code list | `lastUsed.<id>` in `storage.local` | Logout, account removal |
| Seed, code, a `totp` item's decrypted `login` during pairing | Background memory, for one fill, `inline.state` or menu click | The end of that handler |
| Codes shown in the code list | List page memory | The list closing |
| A filled code | The page's field | The page |

## Module layout

- `src/autofill/totp-pair.ts` (new): `matchingTotp(accountId, pageUrl)`, `pairedTotp(...)`, `currentCode(seed, now)` returning `{ digits, period, validUntil } | null`.
- `src/autofill/code-fill.ts` (new): `pickCode()` for the code list.
- `src/autofill/matcher.ts`: `candidatesFor()` takes the type name, `'login'` by default.
- `src/autofill/fill.ts`: `FillTarget.code`; pairing, `code` in `fill.execute`, the copy, "filled something".
- `src/autofill/triggers.ts`: `fillOnPageLoad()` passes `code: false`.
- `src/autofill/fields.ts`: recovery keywords, `codeGroup()`, `isQualifying()` returning the mode.
- `src/autofill/execute.ts`: fill the code group, one character per input for a split group, drop the code otherwise.
- `src/autofill/inline-session.ts`, `src/autofill/inline-host.ts`: `mode` on open and in the session; code rows; code pick.
- `entrypoints/inline-list/list.ts`: code rows, the refresh timer, the code-mode texts.
- `src/menus.ts`: Copy verification code.
- `src/clipboard.ts`: `codeOf`, `clipboardCode`, the clear on lock; registered with the other `vault.locked` listeners in `entrypoints/background.ts`.
- `src/accounts/settings.ts`, `entrypoints/popup/views/settings/Autofill.tsx`: `autoCopyTotp`.
- `locales/en.yml`, `locales/nl.yml`: every new text.
- `test-site/otp-split.html`, `test-site/login-otp.html`, `test-site/recovery-code.html`, linked from `test-site/index.html`.

## Message contract

```ts
/** `code`: the paired or picked item's current code, `null` on page load or without one. */
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
```

No new kind. An `inline.open` without a valid `mode` is read as `login`.

## Risks / Trade-offs

- [A site on the same base domain as the authenticator item gets its code] → The same exposure as a password under the Base domain rule; the user can pick Host or Exact.
- [A page fakes a code field to receive the code] → It only gets the code of an item matching its own URL, after the user picked a login there; a site's own code is worthless to it, since it holds the seed.
- [Ambiguous pairs copy nothing] → Accepted over guessing; the code list and the context menu still reach every item.
- [The code list opens on every SMS or e-mail code field, also for users without authenticator items] → Kept: it opening regardless is what hides from the page whether a code matches (ext-autofill).
- [Clock skew makes codes fail] → As in every authenticator app (ext-vault-browse).
- [A browser restart drops `clipboardCode` while the OS clipboard keeps the code] → Accepted; the code expires within its period, and a restart also loses the clear alarm for every other copy.
- [The clear on lock overwrites whatever the user copied since] → Only while `clipboardCode` is set, which any copy through the extension removes; a copy outside it is not seen, as with every clear (SECURITY.md § Clipboard).

## Open Questions

The design specifies the provisional answer to each.

- Is URL plus username, with the empty-login fallback, the right pairing? Provisional: yes, case-insensitive, never guessing between two.
- May a use-only `totp` copy be used at all? Keepiq's `use-only-shares` lets the web app show its code. Provisional: no surface uses it.
- Should code rows show the code, as Bitwarden's do, or only name and username, so no seed is decrypted before a pick? Provisional: show it.
- Should the lock clear every pending copy, not only a code? Provisional: only a code; the rest would change ext-autofill's and ext-vault-browse's copies.
- Should a pick from the code list also copy the code? Provisional: no; Bitwarden's behaviour here is unverified.
- Should a login's card and detail view offer its paired code now? Provisional: later, as listed out of scope.
- The popup offers no way to fill a code for a site with a `totp` item but no login (SSO sites). Provisional: the code list and the context menu cover it.
