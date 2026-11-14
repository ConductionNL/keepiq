## MODIFIED Requirements

### Requirement: Default Generation
When no regex is provided, the system MUST generate a key from the resolved character set at the specified length. Generation MUST run in the browser with `crypto.getRandomValues`, drawing each character uniformly, so the plaintext value is never sent to the server: only its ciphertext is, once the secret is saved.

#### Scenario: Default generation
@e2e tests/e2e/workflows/key-generator.spec.ts
- GIVEN a request with `length = 16`, `include_special_characters = true`, `excluded_characters = ""`
- WHEN the generator processes the request
- THEN the system MUST return a 16-character random string using alphanumeric and special characters

#### Scenario: Generate in the browser
@e2e tests/e2e/workflows/key-generator.spec.ts
- GIVEN a vault user creating a secret in the new secret dialog on /secrets
- WHEN the user opens the generator and generates a password
- THEN the value MUST be generated without any request to `/api/v1/generate-key`

### Requirement: Frontend Integration
The key generator MUST be callable from the secret creation UI with a configuration modal, and the generated value MUST be inserted directly into the key field. The modal and the browser extension MUST use the same generator module (`src/generator/generator.js`). `POST /api/v1/generate-key` is deprecated for the app's own use and kept only for API clients.

#### Scenario: Generate from the secret creation UI
@e2e tests/e2e/workflows/key-generator.spec.ts
- GIVEN a user is creating a secret
- WHEN they open the key generator modal and generate a value
- THEN the generated value MUST be inserted directly into the key field
