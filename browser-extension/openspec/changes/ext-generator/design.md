## Context

`ext-vault-browse` delivers the popup shell with its tabs, the per-account settings record in `storage.local`, the sync loop, the copy helper with clipboard clearing, and the lock screen from `ext-accounts-and-unlock`. The popup is React (ADR-004): this change adds views, components and hooks under `entrypoints/popup/` and keeps generation in pure functions under `src/generator/`. This change fills the Generator tab. Bitwarden generates everything on the client; Keepiq's `POST /api/v1/generate-key` exists but is unused here because the generator must work locked and offline (ADR-003). Keepiq's organisation policy is served by `SettingsController::getPolicy` at `GET /api/settings/policy` (any authenticated user) and carries `policy_enabled`, `generator_min_length` (at least 8 when set, default 12), the four `generator_require_*` booleans, plus `min_zxcvbn_score`, `block_on_hibp_hit`, `policy_exempt_types` and the two master password floors, which the generator does not use.

## Goals / Non-Goals

**Goals:**

- Bitwarden-identical generator behaviour, defaults and ranges for Password, Passphrase and Username.
- Unbiased randomness from `crypto.getRandomValues` with guaranteed minimum counts.
- Options per account, session history, pick mode for the item form, policy clamping, all usable while locked and offline.
- Unit-testable generator functions with a `vitest` property test task.

**Non-Goals:**

- Forwarded email alias providers.
- Server-side generation or any network call during generation.
- Persisting history beyond the session (open question below).
- Password strength scoring or breach checks; those belong to the item form and to Keepiq's own policy handling.

## Decisions

- **Generation runs in the popup, state lives in the background.** The generator functions are pure and use WebCrypto available in the popup, so a round trip per keystroke would only add latency. Components call them directly; the generated value is React state in the `Generator` view and is dropped on unmount. Options, history, the cached policy, the account email and the active tab hostname are owned by the background and reach components only through two hooks: `useGeneratorOptions` (one `generator.getContext` on mount, `generator.saveOptions` on every change, returns the sanitised options, policy, email and host) and `useGeneratorHistory` (`generator.history.list`, `add`, `clear`). No component touches `browser.*` or `src/api`. Alternative: generate in the background, rejected for latency on slider drags.
- **Unbiased sampler by rejection.** `randomInt(maxExclusive)` draws a `Uint32` and rejects values at or above the largest multiple of `maxExclusive` below 2^32, so no modulo bias. Alternative: `value % max`, rejected because it biases toward low indices on a 7776-word list.
- **Bitwarden's position algorithm for passwords.** Build a list of class slots (`u`, `l`, `n`, `s` for each minimum, `a` for the rest), Fisher-Yates shuffle it with the same sampler, then fill each slot from its set. Every enabled class gets a minimum of at least 1 before building, as Bitwarden does. Alternative: generate then retry until constraints hold, rejected for unbounded loops at short lengths.
- **Wordlist as a static resource, not a TS module.** `public/wordlist/eff-large.txt` (one word per line, about 62 KB) is fetched with `fetch(browser.runtime.getURL('/wordlist/eff-large.txt'))` on first use and cached in a module-level promise in `src/generator/wordlist.ts`. Extension pages may fetch their own resources on both browsers without `web_accessible_resources`. Alternative: bundle as an array literal, rejected because it would load on every popup open.
- **History in `storage.session`, keyed by account.** Background memory dies with the MV3 worker after about 30 seconds idle, so a pure in-memory list would vanish mid-session. `storage.session` matches ADR-002's handling of the private key and is cleared on the same events. Firefox below 115 has no `storage.session`; there the background page is persistent and the list lives in its memory. Alternative: `storage.local`, rejected because generated values are plaintext secrets.
- **Policy cached in `storage.local` per account.** The policy is not secret and must be available locked and offline. The background fetches it after unlock and after each sync and only replaces the cache on a 2xx response. The popup receives the already-mapped clamp `{ minLength, requireUpper, requireLower, requireDigit, requireSymbol } | null`, where `null` means `policy_enabled` false or nothing cached. Alternative: fetch on Generator open, rejected because it would fail locked and offline.
- **Clamp precedence.** Sanitisation order is: spec ranges, then policy (raise length minimum, force required classes on, raise their minimums to 1), then Bitwarden's "length at least the sum of minimums". Clamped values are written back to the stored options so the stored record never disagrees with what the user sees.
- **Pick mode via a callback in navigation state.** The item form (`ext-vault-edit`) navigates to the `Generator` view with `{ pick: { field: 'password' | 'login', onPick: (value: string) => void } }` in the shell router's transient state; the view renders "Use this password" only when `pick` is set, calls `onPick(value)` and navigates back, and the form's `useState` for that field takes the value. Nothing leaves React memory. Alternative: a background message, rejected because the value would cross a boundary for no reason.
- **Website name from the background.** `browser.tabs.query({ active: true, lastFocusedWindow: true })` needs the `tabs` permission to expose `url`. The background derives the hostname once per `generator.getContext` so the popup never touches tab APIs. When `url` is undefined the hostname is `null` and the popup disables the Website name sub-mode.
- **Copy through the shared hook.** The copy hook from `ext-vault-browse` under `entrypoints/popup/hooks/` writes the clipboard and asks the background to schedule the clear with `browser.alarms`; `GeneratedValue` calls it and adds nothing clipboard-specific.

## Module layout

New:

- `src/crypto/random.ts`: `randomInt`, `randomPick`, `shuffle`, `randomString`.
- `src/generator/options.ts`: option types, Bitwarden defaults, `sanitize(options, policy)`.
- `src/generator/password.ts`, `src/generator/passphrase.ts`, `src/generator/username.ts`: pure generators.
- `src/generator/wordlist.ts`: lazy loader with a cached promise.
- `src/generator/policy.ts`: maps the `GET /api/settings/policy` body to `GeneratorPolicy | null`.
- `src/generator/history.ts`: background-side ring buffer over `storage.session` with the Firefox memory fallback.
- `src/generator/*.test.ts`: vitest property tests.
- `public/wordlist/eff-large.txt`, `public/wordlist/LICENSE.txt`.
- `entrypoints/popup/views/Generator.tsx`: the three sub-tabs; the Password, Passphrase and Username panels are local function components in this file because nothing else reuses them.
- `entrypoints/popup/views/GeneratorHistory.tsx`: history list with per-entry copy and Clear.
- `entrypoints/popup/components/GeneratedValue.tsx`: monospace output, colour-coded digits and symbols, Regenerate and Copy, optional "Use this password".
- `entrypoints/popup/components/LengthSlider.tsx`: slider paired with a number input, takes `min`, `max` and a `lockedBy` label.
- `entrypoints/popup/components/OptionToggle.tsx`: labelled switch with an optional `lockedBy` label.
- `entrypoints/popup/components/SubTabs.tsx`: the Password, Passphrase, Username tab strip.
- `entrypoints/popup/hooks/useGeneratorOptions.ts`, `entrypoints/popup/hooks/useGeneratorHistory.ts`.

Edited:

- `src/messages.ts`: the messages below.
- `entrypoints/background.ts`: arms for the generator messages, policy fetch after unlock and sync, history purge on lock and logout.
- `entrypoints/popup/App.tsx` (the shell from `ext-vault-browse`): register the Generator tab and the lock-screen link.
- `entrypoints/popup/popup.css`: colour-coded output tokens and generator control styles.
- `package.json`: `vitest` dev dependency and `test` script.
- `wxt.config.ts`: add `tabs` to `permissions` only if `ext-vault-browse` did not.

## Message contract

Additions to `src/messages.ts`; all are popup to background request/response.

```ts
export type GeneratorType = 'password' | 'passphrase' | 'username'

export interface GeneratorOptions {
	type: GeneratorType
	password: {
		length: number; uppercase: boolean; lowercase: boolean; number: boolean; special: boolean
		minNumber: number; minSpecial: number; avoidAmbiguous: boolean
	}
	passphrase: { numWords: number; wordSeparator: string; capitalize: boolean; includeNumber: boolean }
	username: {
		type: 'word' | 'subaddress' | 'catchall'
		wordCapitalize: boolean; wordIncludeNumber: boolean
		subaddressEmail: string; subaddressType: 'random' | 'website'
		catchallDomain: string; catchallType: 'random' | 'website'
	}
}

/** Already mapped from GET /api/settings/policy; null when disabled or not cached. */
export interface GeneratorPolicy {
	minLength: number
	requireUpper: boolean; requireLower: boolean; requireDigit: boolean; requireSymbol: boolean
}

export interface GeneratorHistoryEntry { value: string; type: GeneratorType; createdAt: number }

export interface GeneratorContext {
	options: GeneratorOptions
	policy: GeneratorPolicy | null
	accountEmail: string | null
	websiteHost: string | null
	locked: boolean
}

export type PopupToBackground =
	| /* existing arms */
	| { kind: 'generator.getContext' }                          // -> GeneratorContext
	| { kind: 'generator.saveOptions'; options: GeneratorOptions } // -> GeneratorOptions (sanitised)
	| { kind: 'generator.history.add'; entry: GeneratorHistoryEntry } // -> GeneratorHistoryEntry[]
	| { kind: 'generator.history.list' }                        // -> GeneratorHistoryEntry[]
	| { kind: 'generator.history.clear' }                       // -> GeneratorHistoryEntry[]
```

Browser differences: `storage.session` is guarded per WXT-AND-BROWSERS.md and falls back to background memory on Firefox below 115; `browser.tabs.query`, `browser.runtime.getURL`, `fetch` of extension resources, `navigator.clipboard.writeText` and `crypto.getRandomValues` behave the same on Chrome MV3 and Firefox MV2.

## Risks / Trade-offs

- [MV3 worker restart between `generator.history.add` calls] → history is read from `storage.session` on every call; nothing is kept in worker memory except the Firefox fallback.
- [Modulo bias or a weak RNG in a hand-written sampler] → rejection sampling in one helper with a vitest distribution test over the 7776-word list; no `Math.random` anywhere under `src/generator`.
- [Policy minimum above 128 or below Bitwarden's 5] → the clamp takes `min(max(policyMin, 5), 128)`; the server refuses values below 8 anyway.
- [Website name leaks the active tab's host into a stored option] → the hostname is never stored; only the sub-mode choice is.
- [Wordlist fetch fails in a restricted profile] → the Passphrase and Random word generators show "Wordlist unavailable"; passwords and emails keep working.
- [Copying a generated value leaves it on the clipboard] → the shared copy helper honours the clipboard-clear setting; the default follows ADR-002 (off, as in Bitwarden).
- [History entries are plaintext in `storage.session`] → same exposure class as the private key bytes ADR-002 already accepts; cleared on lock.
- [A component reaches past the hooks to `browser.*` or `src/api`] → the only browser call outside hooks is the wordlist fetch in `src/generator/wordlist.ts`; lint the ADR-004 boundary in review.
- [Slider drags generate many values and flood history] → history is written on Regenerate, Copy, "Use this password" and when a sub-tab loses focus with a value shown, not on every slider tick.

## Open Questions

- Should history be persisted encrypted in `storage.local` under the public key so it survives lock, as Bitwarden's vault-held history does? Current answer: no, session only.
- Does `ext-vault-browse` add the `tabs` permission? If not, this change adds it for the Website name sub-modes.
- Should the Random word and Plus addressed sub-modes for "website name" strip a leading `www.`? Bitwarden keeps the hostname as is; adopted here.
- Should the lock screen show a full Generator tab bar or only the "Generate a password" link? Adopted: the link, which opens the generator view with the tab bar hidden.
