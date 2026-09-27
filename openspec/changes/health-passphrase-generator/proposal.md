---
kind: code
---

# A passphrase mode for the key generator

## Why

Keepiq's generator makes character strings: random characters from a set, or a string shaped by a regular expression (`lib/Service/KeyGeneratorService.php:179-195`, `POST /api/v1/generate-key` at `appinfo/routes.php:71`, `src/dialogs/KeyGeneratorModal.vue:203-226`). For the passwords people type (a laptop login, a Wi-Fi key read out to a visitor, a master password for a colleague's new vault) a string of words is easier to type and to remember at the same strength. `docs/FEATURES.md:125` lists "Passphrase generation (word-based), Diceware-style passphrases" as a V1 feature. Every competitor in the matrix has it.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `health-08` | Generate a memorable passphrase made of words. | `no`: the generator only produces character strings or regex-shaped ones; there is no word mode |

### Demand

No demand row. Five competitors rate it yes.

### Competitors rated yes

- Bitwarden: "libs/tools/generator/core/src/metadata/password/eff-word-list.ts passphrase from the EFF word list Note: Passphrase generator with word count, separator, capitalisation and number options."
- 1Password: Memorable Passwords from words, including Dutch (https://support.1password.com/generate-website-password/).
- Passbolt: "src/shared/lib/SecretGenerator/SecretGenerator.js:28 passphrase type; ... PassphraseGeneratorWords.js word list; ... ConfigurePassphraseGenerator.js:115 number of words."
- Keeper: "generate a highly secure, random, yet easy-to-remember passphrase" (https://docs.keeper.io/user-guides/web-vault#passphrase-generator).
- Nextcloud Passwords: "src/lib/Provider/Words/LocalWordsProvider.php, LeipzigCorporaProvider.php, SnakesWordsProvider.php, AutoWordsProvider.php word sources; src/lib/Controller/Api/ServiceApiController.php:106 wordsService->getPassword."

## What Changes

- **A passphrase mode.** `POST /api/v1/generate-key` accepts `mode: "passphrase"` with a word count (4 to 12, default 5), a separator, whether to capitalise each word, and whether to add a digit. Words are drawn uniformly with a cryptographically secure random source from the EFF large word list (7,776 words).
- **In the generator dialog.** `KeyGeneratorModal.vue` gets a Password or Passphrase switch with the passphrase options and shows the passphrase's strength with the existing meter.
- **Policy still wins.** When the organisation password policy is on, a passphrase is extended until it meets the policy's length floor and gets a digit, a capital or a symbol separator when the policy requires that class. An administrator can switch passphrases off in the policy section.

## Capabilities

### New Capabilities

- `passphrase-generator`: word-based passphrases from the key generator, bounded by the organisation password policy.

### Modified Capabilities

- None in delta form. `key-generator` and `org-password-policies` keep their requirements; this change's requirements add the mode and state how the policy applies to it.

## Impact

- **Backend**: `KeyGeneratorService` (a passphrase branch and the policy fit), `KeyGeneratorController` (the new parameters), a word list resource under `lib/Resources/` with its licence recorded for REUSE, `generator_allow_passphrase` in the policy settings.
- **Frontend**: `KeyGeneratorModal.vue`, `OrgPasswordPolicySection.vue`.
- **Database**: none.
- **Security**: the same exposure as today's generator, which runs on the server by the `key-generator` spec: the value is returned once and never stored or logged.
- **Cross-app**: none.
