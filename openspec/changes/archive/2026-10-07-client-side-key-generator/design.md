# Design: generate keys and passphrases in the browser

## Context

- `KeyGeneratorService` and `KeyGeneratorRegexParser` generate on the server; `KeyGeneratorModal.vue` called `POST /api/v1/generate-key`.
- `org-password-policies` "Generator Locked to Policy" made the server clamp authoritative.
- Every other value check in Keepiq (zxcvbn floor, breach block) already runs in the browser before encryption, under the honest-client model: the server cannot see a value, so it cannot check one.

## Decisions

**D1. One generator module, in the browser.** `src/generator/generator.js` is a pure port of the server generator: the same character classes, the OWASP symbol set, the 8 to 128 length range, exclusions, the minimum set of 2 characters, the regex mode (first `{n}` or `{n,m}` quantifier, first character class, negated classes as printable ASCII, PHP-style delimiters accepted) and the same messages. Randomness is `crypto.getRandomValues` with rejection sampling, so no index is favoured. The extension imports the module through a relative path, as it imports `src/totp`.

**D2. The policy clamp follows the generator into the browser.** The same clamp as the server: raise the length to the floor, force required classes into the set and the output, refuse a regex that cannot meet the policy. Moving it to the browser loses nothing that mattered: the server never saw the saved value, so it could never verify that a saved value was generated, and a client could always type its own. The clamp is the same honest-client guarantee as the save checks.

**D3. Passphrases from the EFF large word list.** 7,776 words, about 12.9 bits each, drawn uniformly. 4 to 12 words (default 5), a separator (default `-`), optional capitals and one digit. With the policy on, the passphrase is made to comply rather than refused: capitals for a required upper case, a digit for a required digit, a random symbol separator for a required symbol when the chosen separator is not one, and words added until the length floor is met. `generator_allow_passphrase` (default true) lets an administrator switch the mode off; the dialog then offers Password only.

**D4. The endpoint stays, deprecated.** No caller in the app or the extension remains. API clients may still call it, so it keeps working and is marked deprecated for removal in a later release.

## Risks / Trade-offs

- **Two implementations until the endpoint goes.** The vitest suite mirrors `KeyGeneratorServiceTest` case for case, so drift shows as a failing test.
- **The word list adds about 69 kB to the bundle.** Accepted; it is text that compresses well, and only the generator imports it.
