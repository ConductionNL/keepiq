## Context

The popup shell already shows a Generator tab with a placeholder (`Shell.tsx`, `TabBar`, `PopupTab`, `TABS` in `requests.ts`). This change fills it. Generation is client-side, because the generator must work locked and offline. Keepiq's `POST /api/v1/generate-key` stays unused (ADR-003).

Keepiq already ships a client-side generator, `../src/generator/generator.js` and `username.js`. Its own header says the extension imports it. It applies the organisation policy to passwords and passphrases, and the web and Kotlin apps run its seeded vectors (`../tests/vectors/generator/cases.json`).

The policy is served by `SettingsController::getPolicy` at `GET /api/settings/policy` (`#[NoAdminRequired]`, `../appinfo/routes.php`). See ADR-003 for the fields used.

## Goals / Non-Goals

**Goals:**

- The same output as Keepiq's shared generator for the same random source, proven by the shared vectors.
- Bitwarden's Generator layout and flows (ADR-001).
- Options per account, session history, pick mode for the item form and the organisation policy, all usable offline; generation and options also while locked.

**Non-Goals:**

- Forwarded email alias providers.
- Generating from a regex. The shared module supports it; the popup has no UI for it.
- Server-side generation, or any network call during generation.
- History beyond the session.
- Strength scoring or breach checks.

## Decisions

- **Port the shared generator; don't write a new one.**
  - The Keepiq policy contract requires passphrases to meet the floor and the required classes. Bitwarden's generator can't do that.
  - The vectors keep every Keepiq client generating the same way.
  - The shared module raises the length to the sum of `max(min, 1)` over the enabled classes, places characters without positional bias, caps the floor at 128 and leaves hyphenated words out of passphrases.
- **Modules safe for the popup.**
  - `random`, `options`, `policy`, `password`, `passphrase`, `username`, `wordlist` and `generate` under `src/generator/` import nothing from the popup, the background, `src/api` or `src/crypto` (ADR-004), so the popup may import them as it does `src/totp`.
  - `store.ts`, `history.ts` and `queue.ts` are background-only.
  - `ext-autofill`'s "Generate password and copy" will call it from the background with the stored options and policy.
- **Generation runs in the popup; state lives in the background.**
  - The generated value is React state in `Generator` and is dropped on unmount.
  - Options, history, the cached policy and the account email reach components only through `useGeneratorOptions` and `useGeneratorHistory`, the only senders of `generator.*` requests.
  - A round trip per slider tick would only add latency.
- **Unbiased randomness.** `randomInt(min, max)` in `src/generator/random.ts` uses rejection sampling over `crypto.getRandomValues`, with the web module's signature, so the vectors' seeded source plugs in. There is no `Math.random` under `src/` or `entrypoints/`.
- **Failures are codes.** The port returns a code for each of the web module's `GeneratorError` messages. The popup words `needs_email`, `needs_domain`, `no_website`, `passphrase_off` and `wordlist_unavailable`; the rest (length, empty set, no kind, word count) can't follow from `sanitize`d and `effective` options, so `generate` throws on them as a bug. The background sends no text (ext-i18n).
- **Wordlist as a static resource.**
  - `public/wordlist/eff-large.txt` is generated from `../src/generator/eff-large-wordlist.js`, and a test checks the two are equal.
  - `src/generator/wordlist.ts` fetches it through `browser.runtime.getURL` on first use and caches the promise. Its loader is injectable for tests.
  - Extension pages may fetch their own files without `web_accessible_resources`.
  - The licence is CC BY 3.0 US.
- **Options under `generatorOptions.<id>` in `storage.local`, not in `settings.<id>`.**
  - `writeSettings` replaces `settings.<id>` whole.
  - The stored record holds the user's own values, sanitised against the ranges only. Saves run through one queue, so a slider's older save never lands last.
  - `effective(options, policy)` in `src/generator/options.ts` gives what the controls show and what generation uses: the policy plus the length raised to `minimumLength`. It is never written back, so a relaxed policy or lowered minimum restores the user's choice.
  - Kept on logout, removed by `purge` on account removal.
- **Policy cached under `generatorPolicy.<id>` in `storage.local`.**
  - The policy is not secret and is needed while locked and offline.
  - `run()` in `sync.ts` fetches it beside the vault on probe and full syncs, and writes it even when the vault part fails. `refreshPolicy` fetches it after a login or re-login, so the lock screen's generator follows it before the first unlock.
  - The cache is replaced only on a 2xx whose body has a boolean `policy_enabled`, and kept on any other answer.
  - It is written only while the account is logged in, and removed by `markLoggedOut`, `purge` and `undoWrites`, so a logout during a fetch does not bring it back.
  - `src/generator/policy.ts` ports `generatorPolicy`: `null` when `policy_enabled` is not true, a floor of `min(max(8, parseInt(generator_min_length)), 128)`, with 12 when the field is missing or not a number, and `allowPassphrase` unless the field is `false`.
- **History over `sessionValues`.**
  - JSON under `generatorHistory.<id>`, 50 newest first, through one promise queue.
  - `clearKey` removes it with the key, which covers lock, timeout, logout and removal. Browser restart and extension reload clear session storage.
  - `clearKey` bumps a per-account `sessionEpoch`; an add or merge whose request arrived before it drops its write, so a lock or logout meanwhile does not bring the history back.
  - Below Firefox 115, `sessionValues` is background memory.
  - Generated values are plaintext secrets, so never `storage.local`.
- **History while locked.** The lock keeps a walk-up user from reading generated values (ADR-002).
  - `list` and `add` return no entries; `clear` does nothing.
  - A locked add is sealed under `generatorSealed.<id>` to the certificate proven at the last unlock; without one, nothing is recorded.
  - The first unlocked `list` or `add` opens the sealed values and merges them.
  - `clearKey` drops them when a key was held, and always at logout, removal and the two-factor block. Lock all on a locked account, a suite change and the unlock's own suite-race undo keep them.
- **When history records.**
  - It records on Regenerate, Copy, "Use this …", and a switch to another sub-tab with a value shown.
  - It never records on an option change, so slider drags don't flood it.
  - A value equal to the newest entry is not added again.
- **Website name from the popup's tab.**
  - `useCurrentTab` already resolves the tab the popup belongs to, including the originating tab of a pop-out. A background `tabs.query` would see the pop-out window itself.
  - The label is `webUrl(url).hostname`, without a port, for http(s) tabs only. It is never stored.
- **Pick mode through Shell.**
  - `ShellContextValue` gets `generate(target: 'password' | 'username', apply: (value: string) => void, opener)`. Focus returns to `opener`, the clicked button, since Firefox on macOS and Safari don't focus a clicked button.
  - Shell holds `pick` and renders `Generator` over the form. The form stays mounted but `hidden`, as the list does under the form, so its draft survives.
  - The hidden form gets a `ShellContext.Provider` with `footer: null`, so its Save and Cancel don't portal into the bar.
  - A `password` target shows the Password and Passphrase sub-tabs with "Use this password". A `username` target shows the Username sub-tab with "Use this username". Either button sits in `PageFooter`.
  - "Use" calls `apply` and clears `pick`. Back clears `pick` and leaves the field as it was.
  - The draft is popup memory, as while the form is open. The unsaved-changes guard still covers the account switcher and Pop out.
  - The buttons go on the `credential` form kind (login, database, api_key, ssh_key, certificate and custom types):
    - Password on the single-line secret field, never on a multiline one (`ssh_key`, `certificate`);
    - Username on the login field, which this form kind always shows.
  - `TextField` gets an optional `action` prop for the icon button.
- **Locked generator in `App.tsx`.**
  - A `'generator'` panel next to `'switcher'` and `'add_account'`, opened from a link in `Unlock.tsx`, with the Header's back and no tab bar.
  - It is wrapped in a `ShellContext.Provider` with a real `toast` and `accountId`, plus a `Toast` host, so Copy confirms as in the shell.
  - It has no History (see History while locked).
  - Focus moves into the generator on open, and back to the "Generate a password" link on return.
  - No pick mode.
- **Copy through `useClipboard`.** It writes the clipboard, shows "{what} copied" and sends `clipboard.copied`. The background then schedules the clear from `clearClipboardMs` (`src/clipboard.ts`).
- **Text through `i18n.t()`.**
  - New keys go in a `generator:` group in `locales/en.yml` and `locales/nl.yml`, guarded by `locales/locales.test.ts`.
  - Lint refuses letters in JSX text, so labels like "A-Z" are keys too.
  - Relative times come from `entrypoints/popup/relative-time.ts`.

## Module layout

New, each with a test next to it:

- `src/generator/random.ts`: `randomInt`, `shuffle`.
- `src/generator/options.ts`: option types, defaults, ranges, `sanitize` and `effective`.
- `src/generator/password.ts`, `passphrase.ts`, `username.ts`: the ported generators.
- `src/generator/policy.ts`: the policy body to `GeneratorPolicy | null`.
- `src/generator/wordlist.ts`: the lazy loader.
- `src/generator/generate.ts`: `generate`, `applyPolicy` and `passphraseAllowed`, which the popup calls.
- `src/generator/store.ts`: options and the policy cache in `storage.local`.
- `src/generator/history.ts`: the history over `sessionValues`.
- `src/generator/queue.ts`: one-at-a-time queues for option saves and history.
- `public/wordlist/eff-large.txt`, `public/wordlist/LICENSE.txt`.
- `entrypoints/popup/views/Generator.tsx`: the sub-tabs and panels, with an optional `pick`.
- `entrypoints/popup/views/GeneratorHistory.tsx`.
- `entrypoints/popup/components/`: `SubTabs.tsx`, `OptionCheckbox.tsx`, `LengthSlider.tsx` (range plus number), `NumberField.tsx` and `GeneratedValue.tsx`.
- `entrypoints/popup/hooks/`: `useGeneratorOptions.ts`, `useGeneratorHistory.ts` and `useToast.ts` (shared by Shell and the lock screen's generator).

Edited:

- `src/messages.ts`;
- `src/background/requests.ts`;
- `src/vault/sync.ts`, `src/vault/key-store.ts` and `src/accounts/store.ts`;
- `entrypoints/popup/views/Shell.tsx`, `shell-context.ts`, `ItemForm.tsx`, `App.tsx` and `Unlock.tsx`;
- `components/TextField.tsx`, `components/Icon.tsx` (history and generate icons) and `popup.css`;
- `entrypoints/popup/testing.ts` (`fakeBackground` arms);
- `locales/en.yml` and `locales/nl.yml`.

## Message contract

The option types live in `src/generator/options.ts`, so the popup and the background share them; field names follow the web module, whose vectors take these objects as they are. Each new kind goes in `PopupRequest`, `PopupReplies` and `POPUP_REQUEST_KINDS` in `src/messages.ts`, and is handled by `performGenerator` in `src/background/requests.ts`.

```ts
// src/generator/options.ts
export type GeneratorKind = 'password' | 'passphrase' | 'username'

export interface GeneratorOptions {
	tab: GeneratorKind
	password: {
		length: number; includeUppercase: boolean; includeLowercase: boolean; includeDigits: boolean
		includeSpecialCharacters: boolean; minDigits: number; minSpecial: number; avoidAmbiguous: boolean
	}
	passphrase: { words: number; separator: string; capitalise: boolean; includeNumber: boolean }
	username: {
		type: 'word' | 'plus' | 'catchall'
		capitalize: boolean; includeNumber: boolean
		email: string    // empty means the account's email
		domain: string
		mode: 'random' | 'website'
	}
}

/** Mapped from GET /api/settings/policy; null when disabled or not cached. */
export interface GeneratorPolicy {
	minLength: number
	requireUpper: boolean; requireLower: boolean; requireDigit: boolean; requireSymbol: boolean
	allowPassphrase: boolean
}

export interface GeneratorHistoryEntry { value: string; kind: GeneratorKind; createdAt: string } // ISO time

// src/messages.ts
export interface GeneratorContext {
	options: GeneratorOptions
	policy: GeneratorPolicy | null
	accountEmail: string | null
}

export type GeneratorRequest = { accountId: string } & (
	| { kind: 'generator.context' }                                                       // -> GeneratorContext | null
	| { kind: 'generator.saveOptions'; options: GeneratorOptions }                        // -> GeneratorOptions (sanitised) | null
	| { kind: 'generator.history.list' }                                                  // -> GeneratorHistoryEntry[], [] while locked
	| { kind: 'generator.history.add'; entry: Omit<GeneratorHistoryEntry, 'createdAt'> } // -> GeneratorHistoryEntry[], [] while locked
	| { kind: 'generator.history.clear' }                                                 // -> null, a no-op while locked
)
```

- The arms answer for the active account when the message names it; while it is locked, the history arms answer as above. Another or a logged-out account gets `null` for context, saveOptions and clear, and `[]` for list and add, and nothing is written.
- `generator.context` is read on open and never counts as interaction, like `settings.get`. The others count only while the active account is unlocked and the message names it, and `clipboard.copied` (Copy) only while the active account is unlocked, so the lock screen's generator does not keep another account's vault from timing out.
- `generator.history.add` reads the account's session epoch when the request arrives, so a lock or logout while it waits keeps it from writing.

## Risks / Trade-offs

- **The MV3 worker restarts between history calls.** History is read from `sessionValues` on every call.
- **A policy floor above 128.** It is capped at 128 in `policy.ts`, since the server accepts any floor of 8 or more.
- **Website name leaks the tab's host into stored options.** The hostname is never stored; only the sub-mode is.
- **The wordlist fetch fails.** Passphrase and Random word show "Wordlist unavailable"; passwords and emails keep working.
- **A key pair replaced before the unlock.** Values sealed to the old certificate fail to open and are dropped.
- **A component reaches past the hooks.** Only `wordlist.ts` calls a browser API outside the hooks; review checks the ADR-004 boundary.

## Open Questions

- Should the history survive a lock after an unlock, sealed like values generated while locked, as Bitwarden's vault-held history does? Current answer: no, a lock clears it.
- Should the website name strip a leading `www.`? Current answer: no, as Bitwarden.
