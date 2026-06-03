## Why

Doriath can store and share secrets but cannot help users create strong ones. Users must invent passwords themselves or paste from external generators, breaking the workflow and risking weak credentials. A server-side key generator with cryptographic randomness closes this gap, making "create secret with strong password" a single-click operation. This is an MVP-tier feature per docs/FEATURES.md, and OpenConnector (which uses Doriath for API credentials) also benefits from programmatic key generation via the API.

## What Changes

- Add a stateless `KeyGeneratorService` that produces random strings using PHP `random_bytes()` / `random_int()`, configurable by length, special character toggle, character exclusion, and regex override
- Add a `KeyGeneratorController` with a single authenticated REST endpoint for key generation
- Add validation: minimum length 8, regex must contain a length quantifier, resolved character set must have at least 2 distinct characters
- Add frontend integration: a generator button in the secret creation UI that opens a configuration modal and inserts the generated value into the key field
- Special characters use the OWASP recommended set: `!@#$%^&*()-_=+[]{}|;:,.<>?/`

## Capabilities

### New Capabilities
- `key-generator`: Server-side random key/password generation with configurable length, special character toggle (OWASP set), character exclusion, regex override, and REST API endpoint

### Modified Capabilities
_(none — this is a new standalone service with no changes to existing spec requirements)_

## Impact

- **Backend**: New `KeyGeneratorService` (lib/Service/) and `KeyGeneratorController` (lib/Controller/); new route in appinfo/routes.php
- **Frontend**: New Vue component for the generator modal in secret creation; integration with the existing secret creation form
- **API**: One new endpoint (`POST /api/v1/generate-key`) requiring Nextcloud session authentication
- **Database**: None — this is a stateless service with no entity, mapper, or migration
- **Dependencies**: Depends on implement-encryption-suites (Nextcloud session auth) and implement-secrets (secret creation UI for frontend integration)
- **Cross-app**: OpenConnector can call the key generation API to generate credentials programmatically when creating connector secrets
- **Security**: All randomness is server-side via PHP CSPRNG (`random_bytes()` / `random_int()`); no client-side randomness. The endpoint requires authentication — no unauthenticated access.
