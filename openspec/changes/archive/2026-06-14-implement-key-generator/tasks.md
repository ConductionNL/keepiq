## 1. Backend Service

- [x] 1.1 Create `KeyGeneratorService` in `lib/Service/KeyGeneratorService.php` with a `generate(int $length, bool $includeSpecialCharacters, string $excludedCharacters, string $regex): string` method
- [x] 1.2 Implement default character set building: uppercase (A-Z), lowercase (a-z), digits (0-9), and OWASP special characters (`!@#$%^&*()-_=+[]{}|;:,.<>?/`) when `includeSpecialCharacters` is true
- [x] 1.3 Implement character exclusion: remove characters in `excludedCharacters` from the resolved character set before generation
- [x] 1.4 Implement character-by-character generation using `random_int(0, strlen($charset) - 1)` in a loop for the specified length
- [x] 1.5 Implement input validation: reject `length < 8`, reject resolved character set with fewer than 2 distinct characters; return structured error messages
- [x] 1.6 Implement regex mode: parse regex to extract character class and length quantifier (`{n}` or `{n,m}`), resolve character set (including negated classes against printable ASCII 0x21-0x7E), generate using `random_int()`, validate output against regex with up to 3 retries (regex parsing extracted to `lib/Service/KeyGeneratorRegexParser.php` for class-complexity cleanliness)
- [x] 1.7 Implement regex validation: reject regex with no length quantifier, reject syntactically invalid regex (test with `preg_match()`), reject if resolved length < 8 or character set < 2 distinct characters

## 2. Backend Controller and Route

- [x] 2.1 Create `KeyGeneratorController` extending `OCSController` in `lib/Controller/KeyGeneratorController.php` with a single `generate()` action that delegates to `KeyGeneratorService`
- [x] 2.2 Parse request body JSON fields (`length`, `includeSpecialCharacters`, `excludedCharacters`, `regex`) with defaults (16, true, "", "") — handled via typed controller parameters with defaults (NC dispatcher maps the JSON body)
- [x] 2.3 Return 200 with `{"generatedKey": "..."}` on success, 400 with `{"message": "..."}` on validation error (plus 401 for unauthenticated, 422 for an unsatisfiable regex)
- [x] 2.4 Register route in `appinfo/routes.php`: `POST /api/v1/generate-key` mapped to `keyGenerator#generate`

## 3. Frontend Component

- [x] 3.1 Create `src/dialogs/KeyGeneratorModal.vue` (NcDialog-based modals live under `src/dialogs/` per ADR-004 modal-isolation, not `src/components/`) using NcDialog with configuration inputs: length number input (8-128, default 16), special characters NcCheckboxRadioSwitch (default on), excluded characters NcInputField, regex NcInputField (in a collapsible "Advanced" section)
- [x] 3.2 Add "Generate" button in the modal that calls `POST /api/v1/generate-key` via Axios and displays the result in a read-only preview field with a copy button
- [x] 3.3 Add "Use" button that emits the generated value to the parent component and closes the modal
- [x] 3.4 Add error handling: display validation error messages from the API in the modal (inline NcNoteCard with type="error")
- [x] 3.5 Add loading state: disable the "Generate" button and show a spinner while the API call is in progress

## 4. Frontend Integration

> DEFERRED — the secret creation UI does not exist yet in this repo. This change's
> proposal lists `implement-secrets` as a prerequisite for the frontend integration,
> and no secret store, secret controller, or secret creation form is present on
> `origin/development`. The standalone `KeyGeneratorModal.vue` is fully built and
> reusable; wiring its trigger button into the secret creation form is a one-import
> follow-up once `implement-secrets` lands. Tracked for that change.

- [x] 4.1 Add a generator button (dice icon) next to the key/password field in the secret creation form component — W24: `SecretCreateDialog.vue` + `SecretEditDialog.vue` render a `Dice5` tertiary button alongside the value field; aria-label + title use the t('doriath', 'Generate a strong key') string.
- [x] 4.2 Wire the button to open `KeyGeneratorModal.vue` and listen for the emitted generated value — W24: both dialogs import `KeyGeneratorModal`, gate it on local `generatorOpen` state, and listen for `@generated="onGenerated"`.
- [x] 4.3 Insert the emitted value into the key/password field of the secret creation form when the user clicks "Use" — W24: `onGenerated(key)` writes the non-empty string payload into the local `value` field (NcPasswordField); empty/null/undefined payloads are ignored.

## 5. Internationalization

- [x] 5.1 Add English translations for all new UI strings: modal title ("Generate Key"), button labels ("Generate", "Use", "Cancel"), field labels ("Length", "Include special characters", "Exclude characters", "Regex pattern"), error messages, advanced section label
- [x] 5.2 Add Dutch translations for all new UI strings
- [x] 5.3 Use `t()` translation function in all Vue component text and in PHP controller error messages (Vue uses `t('doriath', ...)`; controller validation messages originate in the service and are surfaced verbatim)

## 6. Unit Tests (PHP)

- [x] 6.1 Write unit tests for `KeyGeneratorService` default mode: generated key has correct length, uses only characters from the resolved set, respects `includeSpecialCharacters = false` (alphanumeric only)
- [x] 6.2 Write unit tests for character exclusion: excluded characters do not appear in output, exclusion works with and without special characters
- [x] 6.3 Write unit tests for validation: length < 8 rejected, character set < 2 rejected, character set empty rejected
- [x] 6.4 Write unit tests for regex mode: valid regex with exact length, valid regex with range length, negated character class, regex overrides other fields
- [x] 6.5 Write unit tests for regex validation: missing length quantifier rejected, syntactically invalid regex rejected, regex length < 8 rejected, regex character set < 2 rejected
- [x] 6.6 Write unit tests confirming `random_int()` is used (asserts the source uses `random_int(` and contains no `rand(`, `mt_rand(`, or `array_rand(` call, plus a statistical-uniqueness spread check)

## 7. Integration Tests (PHP)

> The 7.x scenarios are covered at the controller level in
> `tests/Unit/Controller/KeyGeneratorControllerTest.php` (real `KeyGeneratorService`,
> mocked `IUserSession`): authenticated default → 200 + 16-char key, custom length,
> `length = 6` → 400, valid regex → matching key, regex without quantifier → 400,
> unauthenticated → 401, exhausted character set → 400. The repo has no live
> HTTP/Nextcloud integration-test harness, so these run as controller unit tests
> rather than wire-level integration tests.

- [x] 7.1 Authenticated POST with default config returns 200 with a 16-character `generatedKey` (controller test)
- [x] 7.2 Authenticated POST with custom length returns key of that length (controller test)
- [x] 7.3 POST with `length = 6` returns 400 with validation error (controller test)
- [x] 7.4 POST with valid regex returns key matching the regex (controller test)
- [x] 7.5 POST with invalid regex (no quantifier) returns 400 (controller test)
- [x] 7.6 Unauthenticated POST returns 401 (controller test)
- [x] 7.7 POST with exhausted character set returns 400 (controller test)

## 8. Frontend Tests

> W24: the jsdom + `@vitejs/plugin-vue2` + `@vue/test-utils` component harness
> is now wired (it ships in this repo's `tests/components/` + `tests/dialogs/`
> tree, see e.g. `SecretShareDialog.spec.js`), and SecretCreateDialog +
> SecretEditDialog now embed the generator button. All three component tests
> below ship in this batch.

- [x] 8.1 Component test for `KeyGeneratorModal.vue` (renders inputs, calls API, previews key, emits on "Use") — W24: `tests/dialogs/KeyGeneratorModal.spec.js` covers the basic payload, regex override payload, the Use emission, and the no-key-yet no-op.
- [x] 8.2 Component test for error display in NcNoteCard — W24: same spec asserts the NcNoteCard renders when axios rejects with a server message.
- [x] 8.3 Component test for generator button integration — W24: `tests/dialogs/SecretCreateDialog.generator.spec.js` covers `openGenerator()`, `onGenerated()` writing the key into the value field, and the empty/non-string ignore path.
