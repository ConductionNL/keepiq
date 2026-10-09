## ADDED Requirements

### Requirement: Generate a passphrase
The key generator MUST offer a passphrase mode with a word count (4 to 12, default 5), a separator (default `-`), whether to capitalise each word (default false) and whether to add a digit (default false). It MUST draw each word uniformly from the EFF large word list of 7,776 words with `crypto.getRandomValues`, in the browser. A word count below 4 or above 12 MUST be refused with a message that names the allowed range.

#### Scenario: A vault user generates a passphrase for a new secret
@e2e tests/e2e/workflows/key-generator.spec.ts
- **GIVEN** a vault user creating a secret in the new secret dialog on /secrets
- **WHEN** the user opens the generator, switches to Passphrase, picks 6 words with a space as separator and generates
- **THEN** the value field holds six words from the list separated by spaces

#### Scenario: Too few words
@e2e exclude The dialog's number field bounds the count to 4 to 12; the refusal of an out-of-range count is a module contract covered by tests/vitest/generator.spec.js ("refuses fewer than 4 or more than 12 words").
- **GIVEN** a signed-in user
- **WHEN** a passphrase of 3 words is requested
- **THEN** the generator refuses and names the allowed range of 4 to 12 words

### Requirement: Passphrases follow the organisation password policy
When the organisation password policy is on, a generated passphrase MUST meet the policy's length floor and required character classes, by adding words, capitalising, adding a digit or using a symbol separator. An administrator MUST be able to switch passphrases off in the organisation policy section; the generator dialog then offers Password only and the generator refuses the mode.

#### Scenario: The policy requires a digit and 30 characters
@e2e exclude Needs an org-policy write that would leak into every other spec on the shared e2e instance. Covered by tests/vitest/generator.spec.js ("meets a policy of 30 characters and a digit from 4 words").
- **GIVEN** an organisation policy with a length floor of 30 and a required digit
- **WHEN** a user generates a passphrase of 4 words
- **THEN** the passphrase is at least 30 characters long and contains a digit

#### Scenario: An administrator switches passphrases off
@e2e exclude Needs an org-policy write that would leak into every other spec on the shared e2e instance. Covered by tests/components/OrgPasswordPolicySection.spec.js, tests/dialogs/KeyGeneratorModal.spec.js ("offers Password only when the organisation switched passphrases off") and tests/vitest/generator.spec.js.
- **GIVEN** an administrator who switched off passphrases in the organisation policy section of the Keepiq admin settings
- **WHEN** a user opens the generator
- **THEN** the dialog offers Password only
