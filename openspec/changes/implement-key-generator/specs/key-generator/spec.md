## ADDED Requirements

### Requirement: Configuration Fields
The key generator MUST accept the following configuration fields:

| Field | Type | Default | Constraint |
|-------|------|---------|------------|
| `length` | int | 16 | Minimum 8, maximum 128 |
| `includeSpecialCharacters` | bool | true | Toggles OWASP special character set |
| `excludedCharacters` | string | `""` | Characters to remove from the resolved set |
| `regex` | string | `""` | When valid, overrides all other fields |

All fields are optional. When omitted, the default values MUST be used.

#### Scenario: All defaults
- **WHEN** a request is submitted with no configuration fields (empty body or all fields omitted)
- **THEN** the system MUST generate a 16-character string using uppercase letters, lowercase letters, digits, and OWASP special characters

#### Scenario: Custom length only
- **WHEN** a request is submitted with `length = 24` and all other fields omitted
- **THEN** the system MUST generate a 24-character string using the default character set (alphanumeric + special)

### Requirement: Minimum Length
The system MUST reject generation requests where the resolved length is less than 8 characters. This applies both to the `length` field in default mode and to the length extracted from a regex quantifier.

#### Scenario: Length too short in default mode
- **WHEN** a request is submitted with `length = 6` and no `regex`
- **THEN** the system MUST return HTTP 400 with a validation error message indicating the minimum length is 8

#### Scenario: Length too short in regex mode
- **WHEN** a request is submitted with `regex = "^[a-zA-Z]{5}$"`
- **THEN** the system MUST return HTTP 400 with a validation error message indicating the minimum length is 8

#### Scenario: Exact minimum length accepted
- **WHEN** a request is submitted with `length = 8`
- **THEN** the system MUST generate an 8-character string successfully

### Requirement: Special Characters Definition
The special characters set MUST be the OWASP recommended symbol set:

```
!@#$%^&*()-_=+[]{}|;:,.<>?/
```

When `includeSpecialCharacters` is `true`, these characters MUST be included in the character set. When `false`, the character set MUST contain only uppercase letters (A-Z), lowercase letters (a-z), and digits (0-9).

#### Scenario: Special characters enabled (default)
- **WHEN** a request is submitted with `includeSpecialCharacters = true` (or omitted)
- **THEN** the generated key MUST be drawn from a character set containing A-Z, a-z, 0-9, and `!@#$%^&*()-_=+[]{}|;:,.<>?/`

#### Scenario: Special characters disabled
- **WHEN** a request is submitted with `includeSpecialCharacters = false`
- **THEN** the generated key MUST contain only characters from A-Z, a-z, and 0-9

### Requirement: Character Exclusion
Characters listed in `excludedCharacters` MUST be removed from the character set before generation. Exclusion applies after the base set is resolved (including or excluding special characters).

#### Scenario: Exclude ambiguous characters
- **WHEN** a request is submitted with `excludedCharacters = "0Ol1I"`
- **THEN** the generated key MUST NOT contain any of the characters `0`, `O`, `o` (note: only listed chars), `l`, `1`, or `I`

#### Scenario: Exclude some special characters
- **WHEN** a request is submitted with `includeSpecialCharacters = true` and `excludedCharacters = "{}[]"`
- **THEN** the generated key MUST be drawn from the full character set minus `{`, `}`, `[`, and `]`

#### Scenario: Exclusion does not apply in regex mode
- **WHEN** a request is submitted with a valid `regex` and `excludedCharacters = "abc"`
- **THEN** the system MUST ignore `excludedCharacters` and generate based on the regex character class only

### Requirement: Minimum Viable Character Set
After applying `excludedCharacters`, the resolved character set MUST contain at least 2 distinct characters. If the resolved set contains fewer than 2 characters, the system MUST return a validation error before attempting generation.

#### Scenario: Character set exhausted to one character
- **WHEN** a request is submitted with `includeSpecialCharacters = false` and `excludedCharacters` containing all uppercase, lowercase, and digit characters except one
- **THEN** the system MUST return HTTP 400 with a validation error indicating the character set is too small (fewer than 2 distinct characters)

#### Scenario: Character set exhausted to zero
- **WHEN** a request is submitted with `excludedCharacters` containing every character in the resolved base set
- **THEN** the system MUST return HTTP 400 with a validation error indicating the character set is empty

#### Scenario: Two characters remaining is valid
- **WHEN** a request is submitted where the resolved character set has exactly 2 distinct characters
- **THEN** the system MUST generate a key using those 2 characters successfully

### Requirement: Regex Override
When a non-empty `regex` is provided, it MUST override `length`, `includeSpecialCharacters`, and `excludedCharacters`. The system MUST generate a value that matches the provided regex.

A regex is considered valid for generation if and only if:
1. It contains a length quantifier (e.g., `{16}` for exact length, or `{8,16}` for a range)
2. The character set is determinable from the regex (character class or negated class)
3. The resolved length is at least 8
4. The resolved character set has at least 2 distinct characters

#### Scenario: Valid regex with exact length
- **WHEN** a request is submitted with `regex = "^[a-zA-Z0-9!@#]{16}$"`
- **THEN** the system MUST generate a 16-character string matching the regex

#### Scenario: Valid regex with length range
- **WHEN** a request is submitted with `regex = "^[a-zA-Z0-9]{8,16}$"`
- **THEN** the system MUST generate a string with length between 8 and 16 (inclusive) matching the regex

#### Scenario: Valid regex with negated class
- **WHEN** a request is submitted with `regex = "^[^\s<>]{16}$"`
- **THEN** the system MUST generate a 16-character string containing no whitespace, `<`, or `>` characters

#### Scenario: Invalid regex with no length quantifier
- **WHEN** a request is submitted with `regex = "^[a-zA-Z0-9]$"`
- **THEN** the system MUST return HTTP 400 with a validation error explaining the regex must contain a length quantifier

#### Scenario: Invalid regex with syntactically broken pattern
- **WHEN** a request is submitted with `regex = "^[a-z"`
- **THEN** the system MUST return HTTP 400 with a validation error indicating the regex is invalid

#### Scenario: Regex overrides other fields
- **WHEN** a request is submitted with `regex = "^[a-z]{20}$"`, `length = 10`, `includeSpecialCharacters = true`
- **THEN** the system MUST generate a 20-character lowercase-only string, ignoring `length` and `includeSpecialCharacters`

### Requirement: Server-Side Randomness
All random values MUST be generated server-side using PHP's cryptographically secure random number generator (`random_int()` backed by the OS CSPRNG). The system MUST NOT use `rand()`, `mt_rand()`, `array_rand()`, or any non-CSPRNG source. The system MUST NOT accept or incorporate client-supplied entropy.

#### Scenario: Generated key uses CSPRNG
- **WHEN** any key generation request is processed
- **THEN** the implementation MUST use `random_int()` for character selection, which internally uses the OS CSPRNG via `php_random_bytes()`

### Requirement: Authentication
The key generator API endpoint MUST require a valid Nextcloud session. Unauthenticated requests MUST be rejected.

#### Scenario: Authenticated request succeeds
- **WHEN** a user with a valid Nextcloud session sends a generation request
- **THEN** the system MUST process the request and return a generated key

#### Scenario: Unauthenticated request rejected
- **WHEN** a request is sent without a valid Nextcloud session
- **THEN** the system MUST return HTTP 401

### Requirement: API Endpoint
The system MUST expose a REST API endpoint at `POST /api/v1/generate-key` that accepts a JSON body with the configuration fields and returns the generated key in a JSON response.

The response format MUST be:
```json
{
  "generatedKey": "<the generated string>"
}
```

#### Scenario: Successful generation returns JSON
- **WHEN** a valid authenticated request is submitted
- **THEN** the system MUST return HTTP 200 with a JSON body containing the `generatedKey` field

#### Scenario: Validation error returns structured error
- **WHEN** a request fails validation
- **THEN** the system MUST return HTTP 400 with a JSON body containing a `message` field describing the validation error

### Requirement: Frontend Integration
The key generator MUST be accessible from the secret creation UI via a generator button next to the key/password field. Clicking the button MUST open a configuration modal. The generated value MUST be insertable into the key field.

#### Scenario: Generator button visible in secret creation form
- **WHEN** a user opens the secret creation form
- **THEN** a generator button (dice icon) MUST be visible next to the key/password input field

#### Scenario: Modal opens on button click
- **WHEN** a user clicks the generator button
- **THEN** an NcDialog modal MUST open with configuration options: length input, special characters toggle, excluded characters input, and regex input (collapsed by default)

#### Scenario: Generate and preview
- **WHEN** a user clicks "Generate" in the modal
- **THEN** the modal MUST call the API endpoint and display the generated key in a preview field within the modal

#### Scenario: Use generated key
- **WHEN** a user clicks "Use" after a key has been generated
- **THEN** the generated key MUST be inserted into the key/password field of the secret creation form and the modal MUST close

#### Scenario: Regenerate
- **WHEN** a user clicks "Generate" again after a previous generation
- **THEN** the modal MUST call the API again and replace the preview with the new generated key
