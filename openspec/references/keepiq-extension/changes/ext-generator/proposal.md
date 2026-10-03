---
kind: code
depends_on: [ext-vault-browse]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-generator
  - ext-send
  - ext-settings
  - ext-autofill
---

## Why

Users create new accounts while browsing and need a strong password, passphrase or throwaway username without leaving the popup. Bitwarden's Generator tab is the behaviour they already know, and Keepiq's server-side generator cannot serve it because the extension must also work while locked and offline (ADR-001, ADR-003).

## What Changes

- Add the **Generator** tab to the popup shell delivered by `ext-vault-browse`, mirroring Bitwarden's generator: sub-tabs Password, Passphrase and Username.
- Password: length slider plus number input (default 14, range 5 to 128), toggles for A-Z, a-z, 0-9 and `!@#$%^&*`, minimum numbers and minimum special inputs, and "Avoid ambiguous characters". Generation is client-side with `crypto.getRandomValues` and rejection sampling.
- Passphrase: number of words (default 3, range 3 to 20), word separator (default `-`), Capitalize and Include number, drawn from the bundled EFF long wordlist (7776 words, CC BY 3.0).
- Username: Random word (default), Plus addressed email and Catch-all email, each with Bitwarden's "random" or "website name" sub-mode where Bitwarden offers it.
- Output box in monospace with colour-coded digits and symbols, Regenerate and Copy buttons, and a "Use this password" button when opened in pick mode from the item form.
- Generator history: the last 50 generated values, kept in `storage.session`, cleared on lock, shown in a History view with copy per entry and a Clear button.
- Options remembered per account in `storage.local` under the account's settings.
- Organisation password policy: the background fetches `GET /api/settings/policy` on unlock and sync and caches it; the Password tab clamps length and required classes to it and labels clamped controls "Set by your organisation". Locked or offline, the last cached policy applies.
- The whole tab works while locked and offline. No server call happens during generation.

## Capabilities

### New Capabilities

- `credential-generator`: client-side generation of passwords, passphrases and usernames in the popup, with per-account options, session history, clipboard copy, pick mode for the item form and organisation policy clamping.

### Modified Capabilities

None.

## Deviations from Bitwarden

- **Generator is reachable while locked.** Bitwarden's popup shows only the unlock screen when locked. Here the lock screen offers a link to the Generator because the user asked for offline and locked use; history, options and the cached policy are all readable without the private key.
- **Forwarded email alias is omitted.** Bitwarden integrates SimpleLogin, addy.io, Firefox Relay, Fastmail, DuckDuckGo and ForwardEmail from the client with per-service API keys. Keepiq has no such integration and this change does not add one. The Username type select lists only Random word, Plus addressed email and Catch-all email.
- **History is 50 entries in `storage.session`, not 100 encrypted entries in the vault.** Keepiq has no vault-side place for a generator history and the user asked for a session-only list. Cleared on lock, logout, account removal and browser restart.
- **Policy clamps only what Keepiq's policy expresses.** Keepiq's `GET /api/settings/policy` carries a minimum length and four required classes for the password generator. It has no passphrase rules, no forced default type and no override option, so the Passphrase and Username tabs are never clamped and there is no "override" button.
- **Plus addressed email defaults to the Nextcloud account email.** Bitwarden defaults to the Bitwarden account email. When the Nextcloud user record has no email the input starts empty with a placeholder.

## Keepiq API used

- `GET /api/settings/policy` (fetched by the background on unlock and on sync; cached; never called during generation)
- `GET /ocs/v2.php/cloud/user` (read indirectly: the account email cached by `ext-accounts-and-unlock` in `storage.local` seeds the plus addressed email input)

## Impact

- New: `src/crypto/random.ts`, `src/generator/*.ts`, `public/wordlist/eff-large.txt` and its licence file; React views `Generator` and `GeneratorHistory`, components `GeneratedValue`, `LengthSlider`, `OptionToggle` and `SubTabs`, hooks `useGeneratorOptions` and `useGeneratorHistory` under `entrypoints/popup/` (ADR-004).
- Edited: `src/messages.ts` (generator options, policy and history messages), `entrypoints/background.ts` (options, history and policy arms), `entrypoints/popup/App.tsx` and `popup.css` (tab registration, lock-screen link, styles), `wxt.config.ts` only if `ext-vault-browse` did not already add the `tabs` permission.
- Dependencies: `vitest` as a dev dependency with one `npm test` script; the EFF long wordlist (about 62 KB) ships in the bundle. React itself is already present from `ext-accounts-and-unlock`; no new UI dependency.
- Not affected: content scripts, the API client beyond one new read, the vault cache.
