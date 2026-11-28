Every new module, component, hook and view gets a `*.test.ts(x)` next to it. Popup tests use React Testing Library with `fakeBackground` from `entrypoints/popup/testing.ts`; this change adds component tests, as ADR-004 asks to be stated.

## 1. Contract (step 0, before the rest)

- [x] 1.1 Add the generator types and the five `generator.*` arms to `src/messages.ts` (`PopupRequest`, `PopupReplies`, `POPUP_REQUEST_KINDS`), and the option types, defaults and ranges to `src/generator/options.ts`, with `options.test.ts`.
  - Defaults and ranges as the spec's "Password options and defaults", "Passphrase options and generation" and "Username types and defaults" say.
  - Every arm carries `accountId`.

## 2. Generator core (WP1)

- [x] 2.1 Port `randomInt(min, max)` and an unbiased `shuffle` to `src/generator/random.ts`, with `random.test.ts`.
  - Over 1 000 000 draws in 7776 buckets, no bucket is empty and none exceeds twice the mean.
  - No `Math.random` under `src/` or `entrypoints/`.
- [x] 2.2 Port the charset password path of `../src/generator/generator.js` to `src/generator/password.ts`, with `password.test.ts`, and add `src/generator/vectors.test.ts` running `../tests/vectors/generator/cases.json` through `../tests/vectors/seeded-random.mjs`.
  - The regex cases are skipped by name, with the reason.
  - Property tests over 1000 passwords per option set: exact length, minimums met, no disabled class, no ambiguous character when avoided, no positional bias.
- [x] 2.3 Port the passphrase and username generators to `src/generator/passphrase.ts` and `username.ts`, with tests, returning the failure codes from design.md instead of text.
- [x] 2.4 Generate `public/wordlist/eff-large.txt` from `../src/generator/eff-large-wordlist.js`, add `public/wordlist/LICENSE.txt` (EFF, CC BY 3.0 US), and add `src/generator/wordlist.ts` with an injectable loader and a cached promise, with `wordlist.test.ts`.
  - The test checks the file equals the web app's list.
  - A failed load resolves `null`, which `generate` maps to `wordlist_unavailable`, and the next use retries.
- [x] 2.5 Add `effective(options, policy)` to `options.ts` (the policy, plus the length raised to `minimumLength`), with tests.
  - `effective` never writes back.

## 3. Background state (WP2)

- [x] 3.1 Add `src/generator/store.ts` for `generatorOptions.<id>` (saves queued) and `generatorPolicy.<id>` in `storage.local`, `src/generator/policy.ts` (the policy body to `GeneratorPolicy | null`, the floor capped at 128), and `sanitize` and `minimumLength` in `options.ts`, with tests next to each.
  - `sanitize` clamps to the ranges only and keeps the previous classes when the last is turned off.
  - `purge` in `src/accounts/store.ts` removes both. `markLoggedOut` removes the policy. Covered in `store.test.ts`.
- [x] 3.2 Fetch the policy in `run()` of `src/vault/sync.ts` for probe and full syncs, write it even when the vault part fails, and clear it in `undoWrites`; add `refreshPolicy` and call it after a login and a re-login in `src/background/router.ts`. Cases in `sync.test.ts` and `router.test.ts`.
  - The cache is replaced only on a 2xx with a boolean `policy_enabled`, and kept on a network error, a 5xx, a refusal or another body.
  - Written only while the account is logged in.
- [x] 3.3 Add `src/generator/history.ts` over `sessionValues` (`generatorHistory.<id>`, 50 newest first, one queue, the newest value not repeated, no write after a `clearKey` during an add), and add its key to `clearKey`'s remove list, with `history.test.ts` and a case in `key-store.test.ts`.
- [x] 3.4 Handle the `generator.*` arms in `performGenerator` of `src/background/requests.ts`, with cases in `requests.test.ts`.
  - A request for another account than the active one, or for a logged-out one, gets `null` (context, saveOptions, clear) or `[]` (list, add) and writes nothing.
  - `generator.context` is not an interaction; the others are only while the named active account is unlocked, and `clipboard.copied` only while the active account is unlocked.
  - An add reads the session epoch when it arrives, so a lock or logout while it waits writes nothing.
  - While the active account is locked, the history arms follow design.md, History while locked.

## 4. Generator UI (WP3)

- [x] 4.1 Add `SubTabs` (a segmented tab bar), `OptionCheckbox`, `LengthSlider`, `NumberField` and `GeneratedValue` under `entrypoints/popup/components/`, each with its own test file, plus their styles in `popup.css` and a history icon in `Icon.tsx`.
  - `GeneratedValue` colour-codes digits and symbols and copies through `useClipboard`.
  - `OptionCheckbox` and `LengthSlider` take a `lockedBy` label, and `OptionCheckbox` a `lockedHint` id for a row's single reason; `NumberField` takes `hint` and `describedBy`.
- [x] 4.2 Add `useGeneratorOptions` and `useGeneratorHistory` under `entrypoints/popup/hooks/`, with tests, and the generator arms in `fakeBackground`.
  - The hooks are the only senders of `generator.*`.
- [x] 4.3 Add `views/Generator.tsx` with an optional `pick`, and `Generator.test.tsx`.
  - The panels follow the spec: the policy locks "Set by your organisation", the Passphrase sub-tab disabled with "Turned off by your organisation", the website label from `useCurrentTab` (hostname, http(s) only), and the "Enter an email address", "Enter a domain", "No website detected" and "Wordlist unavailable" states.
  - History is recorded per the design's rule.
- [x] 4.4 Add `views/GeneratorHistory.tsx` and its test: newest first, relative time from `relative-time.ts`, Copy per entry, Clear behind `ConfirmDialog`, and the empty state "No generated values yet".
  - Every string of tasks 4 and 5 gets a key in a new `generator:` group in `locales/en.yml` and `locales/nl.yml`.

## 5. Shell integration (WP4, after WP3)

- [x] 5.1 In `Shell.tsx`, render `Generator` for the Generator tab, add the History page, and add pick state with `generate(target, apply, opener)` on `ShellContextValue` and `DEFAULT_SHELL`; focus returns to `opener`.
  - The form stays mounted but hidden under a provider with `footer: null`; "Use" and Back clear `pick`.
  - Move the placeholder test in `Shell.test.tsx` to Send.
- [x] 5.2 Give `TextField` an optional `action`, and add the Password and Username generate buttons to the `credential` form kind in `ItemForm.tsx`, with cases in `components.test.tsx` and `ItemForm.test.tsx`.
  - No button on a multiline secret.
- [x] 5.3 Add a `'generator'` panel to `App.tsx` with a `ShellContext.Provider`, a `Toast` host and the Header's back, without History, opened by a "Generate a password" link in `Unlock.tsx`, with cases in `App.test.tsx` and `Unlock.test.tsx`.
  - Focus moves into the generator, and back to the link on return.

## 6. Records (WP5)

- [x] 6.1 Bring proposal, design, spec and tasks in line with the code, the i18n rules and the shared generator, and update ADR-002 (history in session storage), ADR-003 (the policy fields and their versions), `openspec/README.md` (the chain), `ARCHITECTURE.md`, `SECURITY.md`, and `../docs/browser-extension/using.md` and `privacy.md`.

## 7. Verification

- [x] 7.1 Run `npm test`, `npm run typecheck`, `npm run lint`, `npm run build` and `npm run build:firefox`.
  - `grep -rn "chrome\." src/ entrypoints/` matches only comments.
  - `grep -rn "Math.random" src/ entrypoints/` matches nothing outside tests.
- [ ] 7.2 Load `.output/chrome-mv3/` and `.output/firefox-mv2/manifest.json` and walk the spec's scenarios in both browsers. Include:
  - a pop-out;
  - the locked generator with the network off;
  - a test policy with passphrases turned off;
  - pick mode on a `login` and a `database` item;
  - history clearing on lock, no History on the lock screen, and a value generated while locked showing after the unlock;
  - options surviving a popup close and an account switch.
