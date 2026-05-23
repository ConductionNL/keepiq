## Context

Doriath is an encrypted secrets manager for Nextcloud. After implement-encryption-suites and implement-secrets, users can create and manage encrypted secrets through the vault UI. However, users must supply their own password values — there is no built-in generation capability.

The existing codebase has: Controllers and Services following the Controller-to-Service pattern (ADR-008), Vue 2.7 + Pinia frontend with @conduction/nextcloud-vue components, and Vue Router (hash mode) with a lock screen route guard. The secret creation flow lives in the frontend (secret store + creation form component).

This change adds a stateless key generation service. There are no new database tables, no entities, no mappers, and no migrations. The feature is a pure computation service exposed via a single API endpoint and integrated into the secret creation UI.

## Goals / Non-Goals

**Goals:**
- Implement a PHP `KeyGeneratorService` that produces cryptographically random strings using `random_bytes()` / `random_int()`
- Implement a `KeyGeneratorController` with a single POST endpoint for authenticated key generation
- Support configurable length (min 8, default 16), special character toggle (OWASP set), character exclusion, and regex override
- Validate inputs: minimum length, regex must have length quantifier, character set must have at least 2 distinct characters
- Integrate a generator button and configuration modal into the secret creation UI
- Insert generated values directly into the key field of the secret creation form

**Non-Goals:**
- Password strength indicator on generated keys — V1 tier
- Pronounceable password option — V1 tier
- Passphrase generation (word-based / diceware) — V1 tier
- Storing generation history or configuration presets — not planned
- Client-side generation as fallback — all randomness must be server-side

## Decisions

### D1: Stateless Service — No Database Tables

The key generator is a pure computation service. It takes configuration parameters, generates a random string, and returns it. No state is persisted — no entity, no mapper, no migration, no seed data.

**Why:** There is nothing to store. The generated key is returned to the client and (optionally) encrypted into a secret via the existing secret creation flow. Adding a table for generation history or saved presets would be scope creep beyond MVP.

**Alternatives considered:**
- Store last-used configuration per user in `doriath_settings`: Rejected for MVP — adds unnecessary complexity. The frontend can remember the last config in component state or localStorage if needed later.

### D2: Randomness Source — PHP `random_int()` with Character Set Indexing

The generator builds a character set string from the configuration, then uses `random_int(0, strlen($charset) - 1)` in a loop to pick characters. `random_int()` uses the OS CSPRNG (via `php_random_bytes()` internally) and is suitable for cryptographic use.

For regex mode, the generator extracts the character set from the regex character class and the length from the quantifier, then generates using the same `random_int()` approach. It validates the output against the regex as a post-condition check.

**Why:** `random_int()` is the PHP standard for cryptographically secure random integers. It is simpler and more auditable than building a custom byte-to-character mapping from `random_bytes()`. The character-set-indexing approach guarantees uniform distribution across the allowed characters.

**Alternatives considered:**
- `random_bytes()` with modulo mapping: Rejected — modulo bias is a concern unless using rejection sampling, which `random_int()` already handles internally.
- Third-party library (e.g., paragonie/random_compat): Rejected — `random_int()` is native since PHP 7.0 and Nextcloud requires PHP 8.1+.

### D3: Regex Parsing — Extract Character Class and Length Quantifier

When a regex is provided, the service parses it to extract two things:
1. The character class (e.g., `[a-zA-Z0-9!@#]` or `[^\s<>]`)
2. The length quantifier (e.g., `{16}` for exact length, `{8,16}` for range)

The parser uses a simple regex-on-regex approach: match `\{(\d+)(?:,(\d+))?\}` for the quantifier, and extract the character class contents. For negated classes (`[^...]`), the service computes the complement against the printable ASCII range (0x21–0x7E).

The regex is validated before generation:
- Must contain a length quantifier — otherwise the service cannot determine output length
- The extracted length must be at least 8
- The resolved character set must have at least 2 distinct characters

After generation, the output is validated against the original regex as a sanity check. If validation fails (which indicates a parser bug), the service retries up to 3 times before returning an error.

**Why:** Full regex-based generation (e.g., using a DFA walker) is complex and error-prone. Extracting the character set and length is sufficient for the common use cases (custom character classes with fixed length). The post-condition regex check catches any parser mismatches.

**Alternatives considered:**
- Full regex-to-DFA generation library: Rejected — over-engineered for MVP. The spec requires a length quantifier anyway, which constrains the regex to simple patterns.
- Client-side regex generation with server validation: Rejected — violates the server-side randomness requirement.

### D4: API Design — Single POST Endpoint

One endpoint: `POST /api/v1/generate-key`

Request body (JSON):
```json
{
  "length": 16,
  "includeSpecialCharacters": true,
  "excludedCharacters": "",
  "regex": ""
}
```

Response (200):
```json
{
  "generatedKey": "xK9#mP2!qR7$nL4@"
}
```

Error responses:
- 400: Validation error (length too short, invalid regex, character set too small)
- 401: Unauthenticated

The endpoint is registered in `appinfo/routes.php` and the controller extends `OCSController` to get Nextcloud's session authentication automatically.

**Why:** A single POST endpoint is the simplest API surface for a stateless generator. POST is appropriate because the request has side-effect-like semantics (generating a new value each time) even though it does not modify server state. OCSController provides session auth out of the box.

**Alternatives considered:**
- GET with query parameters: Rejected — generated keys should not appear in server access logs via URLs. POST keeps the configuration in the request body.
- Separate endpoints per generation mode (default vs regex): Rejected — unnecessary complexity. One endpoint handles both modes based on whether `regex` is provided.

### D5: Frontend Integration — Generator Button with NcDialog Modal

The secret creation form gets a "Generate" button (dice icon) next to the key/password field. Clicking it opens an NcDialog modal with:
- Length slider/input (8–128, default 16)
- Special characters toggle (default on)
- Excluded characters text input
- Regex input (advanced, collapsed by default)
- "Generate" button that calls the API and displays the result
- "Use" button that inserts the value into the key field and closes the modal

The modal is a standalone Vue component (`KeyGeneratorModal.vue`) that emits the generated value to the parent. No Pinia store is needed — the component manages its own state (configuration + generated value) and makes the API call directly via Axios.

**Why:** A modal keeps the generation flow contained without navigating away from secret creation. NcDialog is the standard Nextcloud dialog component. No store is needed because there is no shared state — the generator is a fire-and-forget action.

**Alternatives considered:**
- Inline generation (no modal): Rejected — too many configuration options to display inline without cluttering the form.
- Pinia store for generator state: Rejected — over-engineered. The component has no state that other components need to access.

## Risks / Trade-offs

- **[Regex parser limitations]** The regex parser handles common patterns (character classes with quantifiers) but not arbitrary regex features (lookaheads, backreferences, alternation). Mitigation: Document supported patterns clearly in the UI; the post-condition regex check catches mismatches; unsupported patterns return a clear validation error.
- **[Network round-trip for generation]** Each generation requires an API call. Mitigation: The call is lightweight (no DB, no crypto) and should complete in single-digit milliseconds. A loading spinner in the modal prevents double-clicks. For V1, a "regenerate" button can batch multiple attempts.
- **[Regex character class complement]** Computing the complement of a negated character class (`[^...]`) against printable ASCII could miss edge cases with Unicode. Mitigation: Restrict to printable ASCII (0x21-0x7E) which covers all practical password characters. Document this limitation.

## Seed Data

No seed data applies. This is a stateless service with no database tables.
