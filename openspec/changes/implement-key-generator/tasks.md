## 1. Backend Service

- [ ] 1.1 Create `KeyGeneratorService` in `lib/Service/KeyGeneratorService.php` with a `generate(int $length, bool $includeSpecialCharacters, string $excludedCharacters, string $regex): string` method
- [ ] 1.2 Implement default character set building: uppercase (A-Z), lowercase (a-z), digits (0-9), and OWASP special characters (`!@#$%^&*()-_=+[]{}|;:,.<>?/`) when `includeSpecialCharacters` is true
- [ ] 1.3 Implement character exclusion: remove characters in `excludedCharacters` from the resolved character set before generation
- [ ] 1.4 Implement character-by-character generation using `random_int(0, strlen($charset) - 1)` in a loop for the specified length
- [ ] 1.5 Implement input validation: reject `length < 8`, reject resolved character set with fewer than 2 distinct characters; return structured error messages
- [ ] 1.6 Implement regex mode: parse regex to extract character class and length quantifier (`{n}` or `{n,m}`), resolve character set (including negated classes against printable ASCII 0x21-0x7E), generate using `random_int()`, validate output against regex with up to 3 retries
- [ ] 1.7 Implement regex validation: reject regex with no length quantifier, reject syntactically invalid regex (test with `preg_match()`), reject if resolved length < 8 or character set < 2 distinct characters

## 2. Backend Controller and Route

- [ ] 2.1 Create `KeyGeneratorController` extending `OCSController` in `lib/Controller/KeyGeneratorController.php` with a single `generate()` action that delegates to `KeyGeneratorService`
- [ ] 2.2 Parse request body JSON fields (`length`, `includeSpecialCharacters`, `excludedCharacters`, `regex`) with defaults (16, true, "", "")
- [ ] 2.3 Return 200 with `{"generatedKey": "..."}` on success, 400 with `{"message": "..."}` on validation error
- [ ] 2.4 Register route in `appinfo/routes.php`: `POST /api/v1/generate-key` mapped to `keyGenerator#generate`

## 3. Frontend Component

- [ ] 3.1 Create `src/components/KeyGeneratorModal.vue` using NcDialog with configuration inputs: length number input (8-128, default 16), special characters NcCheckboxRadioSwitch (default on), excluded characters NcInputField, regex NcInputField (in a collapsible "Advanced" section)
- [ ] 3.2 Add "Generate" button in the modal that calls `POST /api/v1/generate-key` via Axios and displays the result in a read-only preview field with a copy button
- [ ] 3.3 Add "Use" button that emits the generated value to the parent component and closes the modal
- [ ] 3.4 Add error handling: display validation error messages from the API in the modal (inline NcNoteCard with type="error")
- [ ] 3.5 Add loading state: disable the "Generate" button and show a spinner while the API call is in progress

## 4. Frontend Integration

- [ ] 4.1 Add a generator button (dice icon) next to the key/password field in the secret creation form component
- [ ] 4.2 Wire the button to open `KeyGeneratorModal.vue` and listen for the emitted generated value
- [ ] 4.3 Insert the emitted value into the key/password field of the secret creation form when the user clicks "Use"

## 5. Internationalization

- [ ] 5.1 Add English translations for all new UI strings: modal title ("Generate Key"), button labels ("Generate", "Use", "Cancel"), field labels ("Length", "Include special characters", "Exclude characters", "Regex pattern"), error messages, advanced section label
- [ ] 5.2 Add Dutch translations for all new UI strings
- [ ] 5.3 Use `t()` translation function in all Vue component text and in PHP controller error messages

## 6. Unit Tests (PHP)

- [ ] 6.1 Write unit tests for `KeyGeneratorService` default mode: generated key has correct length, uses only characters from the resolved set, respects `includeSpecialCharacters = false` (alphanumeric only)
- [ ] 6.2 Write unit tests for character exclusion: excluded characters do not appear in output, exclusion works with and without special characters
- [ ] 6.3 Write unit tests for validation: length < 8 rejected, character set < 2 rejected, character set empty rejected
- [ ] 6.4 Write unit tests for regex mode: valid regex with exact length, valid regex with range length, negated character class, regex overrides other fields
- [ ] 6.5 Write unit tests for regex validation: missing length quantifier rejected, syntactically invalid regex rejected, regex length < 8 rejected, regex character set < 2 rejected
- [ ] 6.6 Write unit tests confirming `random_int()` is used (mock/verify no calls to `rand()`, `mt_rand()`, or `array_rand()`)

## 7. Integration Tests (PHP)

- [ ] 7.1 Write integration test for the API endpoint: authenticated POST with default config returns 200 with a 16-character `generatedKey`
- [ ] 7.2 Write integration test: authenticated POST with custom length returns key of that length
- [ ] 7.3 Write integration test: POST with `length = 6` returns 400 with validation error
- [ ] 7.4 Write integration test: POST with valid regex returns key matching the regex
- [ ] 7.5 Write integration test: POST with invalid regex (no quantifier) returns 400
- [ ] 7.6 Write integration test: unauthenticated POST returns 401
- [ ] 7.7 Write integration test: POST with exhausted character set returns 400

## 8. Frontend Tests

- [ ] 8.1 Write component test for `KeyGeneratorModal.vue`: renders all configuration inputs with defaults, calls API on "Generate" click, displays generated key in preview, emits value on "Use" click
- [ ] 8.2 Write component test for error display: shows validation error from API response in NcNoteCard
- [ ] 8.3 Write component test for generator button integration: button visible next to key field, opens modal on click, key field populated after "Use"
