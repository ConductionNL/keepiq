# Key Generator Specification

**Status**: in-progress

**OpenSpec changes:**
- `implement-key-generator` (2026-03-31) — Full implementation: server-side generation, configurable rules, regex override, secret creation UI integration

## Purpose

@e2e exclude No key-generator UI is built in v0.1; the generator runs server-side and all scenarios exercise the REST API validation logic — covered by PHPUnit integration tests, not Playwright UI flows.

The key generator produces random secret values (passwords, API keys, tokens) according to configurable rules. It is available both as a standalone API endpoint and as an integrated tool during secret creation.

## Requirements

### Requirement: Configuration Fields
The key generator MUST accept the following configuration:

| Field | Type | Default | Notes |
|-------|------|---------|-------|
| `length` | int | 16 | Minimum 8 |
| `include_special_characters` | bool | true | OWASP recommended symbols (see below) |
| `excluded_characters` | string | `""` | Characters to exclude from the character set |
| `regex` | string | `""` | When valid (see below), overrides all other fields |

### Requirement: Minimum Length
The system MUST reject generation requests where the resolved length is less than 8 characters.

#### Scenario: Length too short
- GIVEN a request with `length = 6` and no regex
- WHEN the generator processes the request
- THEN the system MUST return a validation error

### Requirement: Regex Override
When a `regex` is provided, it MUST override `length`, `include_special_characters`, and `excluded_characters`.

A regex is considered **valid** for generation if and only if:
1. The exact length of the key to generate is defined in the regex (e.g., `{16}` or `{8,16}`)
2. The allowable character set is defined

Valid examples: `^[a-zA-Z0-9!@#]{16}$`, `^[^\s<>]{16}$`

Invalid example: `^[^\s<>]$` — no length quantifier, cannot determine output length

#### Scenario: Valid regex
- GIVEN a request with a valid regex
- WHEN the generator processes the request
- THEN the system MUST generate a value matching the regex and return it

#### Scenario: Invalid regex (no length)
- GIVEN a request with a regex that has no length quantifier
- WHEN the generator processes the request
- THEN the system MUST return a validation error explaining the regex is invalid

### Requirement: Default Generation
When no regex is provided, the system MUST generate a key from the resolved character set at the specified length.

#### Scenario: Default generation
- GIVEN a request with `length = 16`, `include_special_characters = true`, `excluded_characters = ""`
- WHEN the generator processes the request
- THEN the system MUST return a 16-character random string using alphanumeric and special characters

### Requirement: Special Characters Definition
The special characters set is defined as the OWASP recommended symbol set:

```
!@#$%^&*()-_=+[]{}|;:,.<>?/
```

This is the authoritative set used when `include_special_characters = true`. Users requiring finer control may use `excluded_characters` or `regex`.

### Requirement: Character Exclusion
Characters listed in `excluded_characters` MUST be removed from the character set before generation.

### Requirement: Minimum Viable Character Set
After applying `excluded_characters`, the resolved character set MUST contain at least 2 distinct characters. If the resolved set contains fewer than 2 characters, the system MUST return a validation error before attempting generation.

#### Scenario: Character set exhausted
- GIVEN a request where `excluded_characters` removes all but 1 (or all) characters from the resolved set
- WHEN the generator processes the request
- THEN the system MUST return a validation error indicating the character set is too small

### Requirement: Authentication
The key generator API endpoint MUST require a valid Nextcloud session. Unauthenticated requests MUST be rejected with HTTP 401.

### Requirement: Frontend Integration
The key generator MUST be callable from the secret creation UI with a configuration modal, and the generated value MUST be inserted directly into the key field.

## User Stories

- As a user creating a secret, I want to generate a strong random password so that I don't have to think of one myself
- As a user, I want to configure the generated password (length, special characters) to match the requirements of the target system
- As a user, I want to exclude specific characters (e.g., ambiguous characters like `0`, `O`, `l`, `1`) from generated passwords
- As a developer, I want to use the key generator API to generate secrets programmatically

## Acceptance Criteria

- [ ] Generator accepts `length`, `include_special_characters`, `excluded_characters`, and `regex`
- [ ] Default values apply when fields are omitted
- [ ] Length below 8 is rejected with a validation error
- [ ] A valid regex overrides all other configuration fields
- [ ] An invalid regex (no length quantifier) returns a validation error
- [ ] Generated values match the configured character set and length
- [ ] Special characters use the defined OWASP set (`!@#$%^&*()-_=+[]{}|;:,.<>?/`)
- [ ] A resolved character set with fewer than 2 distinct characters is rejected with a validation error
- [ ] The generator endpoint requires a valid Nextcloud session; unauthenticated requests return HTTP 401
- [ ] The generator is available as a REST API endpoint
- [ ] The generator integrates with the secret creation UI

## Notes

- The generator runs server-side. It must not rely on frontend randomness.
- Related spec: secrets/spec.md (integration at creation time)
