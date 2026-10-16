## 1. Randomness and data

- [ ] 1.1 Add `src/crypto/random.ts` with `randomInt(maxExclusive)` by rejection sampling over `crypto.getRandomValues`, plus `randomPick`, `shuffle` (Fisher-Yates) and `randomString(alphabet, length)`.
  - No `Math.random` under `src/`.
  - `randomInt` throws on `maxExclusive <= 0` or above 2^32.
- [ ] 1.2 Add `public/wordlist/eff-large.txt` (7776 words, one per line, no dice numbers) and `public/wordlist/LICENSE.txt` with the EFF CC BY 3.0 attribution; add `src/generator/wordlist.ts` that fetches it via `browser.runtime.getURL` once and caches the promise.
  - Loader rejects with a typed error the popup maps to "Wordlist unavailable".
- [ ] 1.3 Add `src/generator/options.ts` with the option types, Bitwarden defaults (length 14, A-Z, a-z, 0-9 on, special off, min numbers 1, min special 1, avoid ambiguous on, 3 words, `-`, word username capitalised with number) and `sanitize(options, policy)` applying spec ranges, then policy, then the sum-of-minimums rule.
  - Turning off the last enabled class is rejected by `sanitize`.

## 2. Generators

- [ ] 2.1 Implement `src/generator/password.ts` with Bitwarden's slot algorithm: per-class minimums (at least 1 for every enabled class), shuffled slots, ambiguous set `I O l 0 1`, special set `!@#$%^&*`.
- [ ] 2.2 Implement `src/generator/passphrase.ts`: uniform word draws, separator, capitalize, one digit appended to one random word.
- [ ] 2.3 Implement `src/generator/username.ts`: random word (capitalize, four-digit number), plus addressed email (random 8 lowercase alphanumerics or website host before `@`), catch-all (random or website host as local part).
  - Returns a typed "needs input" result when the email or domain is empty.
- [ ] 2.4 Add `vitest` as a dev dependency with an `npm test` script and property tests in `src/generator/*.test.ts` and `src/crypto/random.test.ts`.
  - 1000 passwords per option set: exact length, minimum counts met, no disabled class, no ambiguous character when avoided.
  - Passphrase word count, separator and single-digit rule.
  - `randomInt` distribution over 7776 buckets has no empty bucket after 1 000 000 draws and no bucket above twice the mean.
  - No component tests in this change (ADR-004).

## 3. Background state

- [ ] 3.1 Extend `src/messages.ts` with `GeneratorOptions`, `GeneratorPolicy`, `GeneratorHistoryEntry`, `GeneratorContext` and the five `generator.*` request arms from design.md.
- [ ] 3.2 In `entrypoints/background.ts` add `generator.getContext` and `generator.saveOptions`: read and write the options under the active account's settings record in `storage.local`, return the cached email from the account record, and derive `websiteHost` from `browser.tabs.query({ active: true, lastFocusedWindow: true })`; add `tabs` to `wxt.config.ts` permissions if `ext-vault-browse` did not.
  - Saved options pass through `sanitize` before storage and before reply.
- [ ] 3.3 Add `src/generator/policy.ts` and a policy fetch of `GET /api/settings/policy` after unlock and after each sync, cached per account in `storage.local`, replaced only on a 2xx response and cleared on logout and account removal.
  - `policy_enabled` false maps to `null`; the other non-generator fields are ignored.
- [ ] 3.4 Add `src/generator/history.ts` over `storage.session` (background memory below Firefox 115), capped at 50 newest-first per account, with the `generator.history.*` arms, and purge it on lock, logout and account removal.
  - Never touches `storage.local`.

## 4. Popup

- [ ] 4.1 Add the components `SubTabs.tsx`, `OptionToggle.tsx`, `LengthSlider.tsx` and `GeneratedValue.tsx` under `entrypoints/popup/components/`: `GeneratedValue` renders the value in monospace with colour-coded digits and symbols, Regenerate, Copy through the shared copy hook and an optional "Use this password" button; `LengthSlider` and `OptionToggle` take a `lockedBy` label that disables the control.
  - Styles in `entrypoints/popup/popup.css`; no webfonts, no CSS-in-JS.
- [ ] 4.2 Add the hooks `useGeneratorOptions.ts` (loads through `generator.getContext`, saves through `generator.saveOptions` on every change, exposes sanitised options, policy, account email and website host) and `useGeneratorHistory.ts` (`generator.history.list`, `add`, `clear`) under `entrypoints/popup/hooks/`.
  - Hooks are the only place generator messages are sent; components never call `browser.*`.
- [ ] 4.3 Add `entrypoints/popup/views/Generator.tsx` with `SubTabs` and the Password panel: `LengthSlider`, four class `OptionToggle`s, minimum numbers and minimum special inputs, avoid ambiguous toggle, policy clamp rendered as `lockedBy="Set by your organisation"`, generation via `src/generator/password.ts` on mount and on every option change with the value in `useState`.
- [ ] 4.4 Add the Passphrase panel (number of words, separator, capitalize, include number) and the Username panel (type select, word toggles, email and domain inputs, Random or Website name sub-mode disabled with "No website detected" when `websiteHost` is null) to `Generator.tsx`, including the "Enter an email address" and "Wordlist unavailable" output states.
- [ ] 4.5 Add `entrypoints/popup/views/GeneratorHistory.tsx` on `useGeneratorHistory`: newest first, `GeneratedValue` styling, relative time, Copy per entry, Clear with confirmation, empty state "No generated values yet"; record history on Regenerate, Copy, "Use this password" and sub-tab blur.
- [ ] 4.6 Wire pick mode: `Generator` reads `{ pick: { field, onPick } }` from the shell router state, shows "Use this password", calls `onPick(value)` and navigates back; hook the item form's Generate buttons if `ext-vault-edit` has landed, otherwise leave the `pick` contract documented in `design.md`.
- [ ] 4.7 Register the Generator tab in `entrypoints/popup/App.tsx` and add the "Generate a password" link on the lock screen that renders `Generator` with the tab bar hidden and no `pick` state.

## 5. Verification

- [ ] 5.1 Manually verify in Chrome and Firefox: defaults match Bitwarden, last class cannot be disabled, minimums raise the length, history caps at 50 and clears on lock, options survive popup close and account switch, policy clamp appears with a test policy and persists offline, generator works while locked with the network disabled.
- [ ] 5.2 Run `npm test`, `npm run typecheck`, `npm run lint`, `npm run build` and `npm run build:firefox`, then load `.output/chrome-mv3/` and `.output/firefox-mv2/manifest.json` in both browsers and open the Generator tab.
  - `grep -rn "chrome\." src/ entrypoints/` matches only comments.
