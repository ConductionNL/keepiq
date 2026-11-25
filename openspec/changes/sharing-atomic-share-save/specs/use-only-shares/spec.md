## MODIFIED Requirements

### Requirement: The server refuses what it can enforce

The system MUST refuse any share whose source is a use-only copy (direct, batch, group, team-folder fan-out, link share, delegation and handover), MUST refuse recipient updates and share-saves of a use-only copy, MUST refuse the recipient's version history ciphertext for it, and MUST leave its value ciphertext out of the recipient's GDPR export.

#### Scenario: A modified client cannot share it onward

- **GIVEN** Bob with a use-only copy
- **WHEN** a script with Bob's session calls `POST /api/v1/shares/register-batch` with that copy as source
- **THEN** the system MUST refuse the request and create no copy

#### Scenario: Bob cannot overwrite it

- **GIVEN** Bob with a use-only copy
- **WHEN** Bob calls `PUT /api/v1/secrets/{id}/share-save` for it
- **THEN** the system MUST refuse the request
