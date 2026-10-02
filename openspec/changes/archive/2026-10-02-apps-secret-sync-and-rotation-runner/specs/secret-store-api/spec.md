## ADDED Requirements

### Requirement: Conditional machine write-back

`PUT /api/v1/app/secrets/{id}` MUST accept an `If-Match` header. When the header is present and does not equal the secret's current strong ETag, the server MUST answer 412 Precondition Failed and MUST NOT change the secret. When the header is absent, the endpoint MUST behave as before. The discovery document MUST advertise `conditionalWrite: true`.

#### Scenario: Stale write is refused

- **GIVEN** an application read secret `pg-app-password` with ETag `A`, and the secret was later updated to ETag `B`
- **WHEN** the application calls `PUT /api/v1/app/secrets/{id}` with `If-Match: A`
- **THEN** the response MUST be 412
- **AND** the stored ciphertext MUST still be the one behind ETag `B`

#### Scenario: Matching write succeeds

- **GIVEN** an application holds the current ETag of its secret
- **WHEN** it calls `PUT /api/v1/app/secrets/{id}` with that ETag in `If-Match` and new ciphertext
- **THEN** the ciphertext MUST be replaced and a new ETag returned

### Requirement: Expiry date in the machine envelope

The `secret` block of the machine envelope MUST include `expiresAt` as an ISO 8601 timestamp, or null when the secret has no expiry. Adding it MUST NOT change any other envelope field. The discovery document MUST advertise `expiresAt: true`.

#### Scenario: Consumer reads the expiry date

- **GIVEN** an application secret with an expiry date of 1 December 2026
- **WHEN** the application fetches it through `GET /api/v1/app/secrets/{id}`
- **THEN** the envelope's `secret.expiresAt` MUST be `2026-12-01T00:00:00+00:00`
