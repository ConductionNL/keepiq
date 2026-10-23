---
kind: code
depends_on: [ext-vault-edit, ext-i18n]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-i18n
  - ext-generator
  - ext-send
  - ext-settings
  - ext-autofill
---

## Why

Users create new accounts while browsing and need a strong password, passphrase or throwaway username without leaving the popup. Bitwarden's Generator tab is the layout they already know. Keepiq's server generator cannot serve it, because the extension must also generate while locked and offline (ADR-003).

## What Changes

- Fill the **Generator** tab, which the popup shell already shows, with Bitwarden's layout: sub-tabs Password, Passphrase and Username.
- Generate with a port of Keepiq's shared generator (`../src/generator/generator.js` and `username.js`), checked against the shared vectors in `../tests/vectors/generator/cases.json`, which the web and Kotlin apps also run. The port follows the shared-module fix, which:
  - raises the length to the sum of the class minimums;
  - places required characters at unbiased positions;
  - caps the policy floor at 128;
  - leaves the four hyphenated EFF words out of passphrases.
- Password:
  - length 14 (8 to 128);
  - A-Z, a-z and 0-9 on, special characters off (the shared set `!@#$%^&*()-_=+[]{}|;:,.<>?/`);
  - minimum numbers and minimum special 1 (0 to 9);
  - "Avoid ambiguous characters" on.
- Passphrase:
  - 5 words (4 to 12) from the bundled EFF long wordlist;
  - separator `-`;
  - Capitalize and Include number, both off.
- Username:
  - Random word, capitalised with four digits;
  - Plus addressed email and Catch-all email, each with a random or website-name label.
- Output: an output box with colour-coded digits and symbols, plus Regenerate and Copy.
- Pick mode in the item form, on every credential type that has the field:
  - Password opens the password and passphrase generator, with "Use this password".
  - Username opens the username generator, with "Use this username".
- History:
  - the last 50 values;
  - recorded on Regenerate, Copy, Use and leaving a sub-tab;
  - kept in session storage only, and cleared with the key.
- Options are kept per account in `storage.local`. They hold the user's own values; the policy is applied on read.
- Organisation policy:
  - fetched on every sync and cached per account;
  - locks the password floor and the required classes, labelled "Set by your organisation";
  - passphrases meet the floor and classes as the shared generator does;
  - `generator_allow_passphrase` false makes the Passphrase sub-tab unavailable, labelled "Turned off by your organisation".
- Works while locked and offline, through a "Generate a password" link on the lock screen.
- All text comes through `i18n.t()`, in English and Dutch (ext-i18n).

## Capabilities

### New Capabilities

- `credential-generator`: client-side generation of passwords, passphrases and usernames in the popup, with per-account options, session history, clipboard copy, pick mode for the item form and organisation policy.

### Modified Capabilities

None.

## Deviations from Bitwarden

- **Generation follows Keepiq's shared generator, not Bitwarden's.** Keepiq's policy contract requires passphrases to meet the floor and classes, and the generator's output must match the cross-client vectors. That makes the ranges and defaults Keepiq's: length 8 to 128, 4 to 12 words, 5 words by default. ADR-001 parity still covers the layout and the flows.
- **Generator is reachable while locked.** Bitwarden's popup shows only the unlock screen when locked. Here the lock screen links to the Generator. History, options and the cached policy are all readable without the private key.
- **Forwarded email alias is omitted.** Keepiq has no alias-service integration. The Username type select lists only Random word, Plus addressed email and Catch-all email.
- **History is 50 entries in session storage, not 100 encrypted entries in the vault.** Keepiq has no vault-side place for it.
- **Policy is Keepiq's.** It sets a floor, four required classes and whether passphrases are allowed. There is no forced default type and no override button.
- **Plus addressed email defaults to the Nextcloud account email.** When the account has none, the input starts empty.

## Keepiq API used

- `GET /api/settings/policy`, fetched by the background on every sync. It is cached and never called during generation. The route and its generator fields exist since Keepiq 0.1.4, and `generator_allow_passphrase` since 0.3.8. Both are older than `MIN_KEEPIQ_VERSION` 0.3.12, so nothing is gated (ADR-003).
- `GET /ocs/v2.php/cloud/user`, read indirectly. The account email that `ext-accounts-and-unlock` caches seeds the plus addressed email.

## Impact

- New:
  - `src/generator/*.ts` and their tests;
  - `public/wordlist/eff-large.txt`, generated from the web app's list, and its licence file;
  - views `Generator` and `GeneratorHistory`;
  - components `GeneratedValue`, `LengthSlider`, `NumberField`, `OptionCheckbox` and `SubTabs`;
  - hooks `useGeneratorOptions`, `useGeneratorHistory` and `useToast` (ADR-004).
- Edited:
  - `src/messages.ts`;
  - `src/background/requests.ts`;
  - `src/vault/sync.ts`, `src/vault/key-store.ts` and `src/accounts/store.ts`;
  - `entrypoints/popup/views/Shell.tsx`, `shell-context.ts`, `ItemForm.tsx`, `App.tsx` and `Unlock.tsx`;
  - `components/TextField.tsx`, `components/Icon.tsx` and `popup.css`;
  - `locales/en.yml` and `locales/nl.yml`.
- No new dependency and no new permission: `tabs` is already granted. The wordlist adds about 62 KB.
- Not affected: content scripts, the vault cache and the API client beyond one read.
