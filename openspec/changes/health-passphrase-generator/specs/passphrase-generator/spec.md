## ADDED Requirements

### Requirement: Generate a passphrase

The key generator MUST accept `mode: "passphrase"` on `POST /api/v1/generate-key` with `words` (4 to 12, default 5), `separator` (default `-`), `capitalise` (default false) and `includeNumber` (default false). It MUST draw each word uniformly from the EFF large word list of 7,776 words with a cryptographically secure random source. A request with fewer than 4 or more than 12 words MUST be rejected with 400. Without `mode` the generator MUST behave as before.

#### Scenario: A vault user generates a passphrase for a new secret

- **GIVEN** a vault user creating a secret in the new secret dialog on /secrets
- **WHEN** the user opens the generator, switches to Passphrase, picks 6 words with a space as separator and generates
- **THEN** the value field holds six words from the list separated by spaces

#### Scenario: Too few words

- **GIVEN** a signed-in user
- **WHEN** the user calls `POST /api/v1/generate-key` with `mode: "passphrase"` and `words: 3`
- **THEN** the response is 400 and names the allowed range

### Requirement: Passphrases follow the organisation password policy

When the organisation password policy is on, a generated passphrase MUST meet the policy's length floor and required character classes, by adding words, capitalising, adding a digit or using a symbol separator. An administrator MUST be able to switch passphrases off in the organisation policy section; the endpoint MUST then reject `mode: "passphrase"` with 400.

#### Scenario: The policy requires a digit and 30 characters

- **GIVEN** an organisation policy with a length floor of 30 and a required digit
- **WHEN** a user generates a passphrase of 4 words
- **THEN** the passphrase is at least 30 characters long and contains a digit

#### Scenario: An administrator switches passphrases off

- **GIVEN** an administrator who switched off passphrases in the organisation policy section of the Keepiq admin settings
- **WHEN** a user requests a passphrase
- **THEN** the response is 400 and the generator dialog offers Password only
