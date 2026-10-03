## ADDED Requirements

### Requirement: Runner works on one application's vault outside the server

The project MUST ship `keepiq-runner`, built from `integrations/runner/`, that authenticates as one Keepiq application with that application's private key and uses only the machine API under `/api/v1/app/*`. It MUST decrypt and encrypt only in its own process, MUST NOT send the private key or any plaintext to Keepiq, and MUST NOT write any plaintext value to its logs or state directory.

#### Scenario: Runner starts with an application key

- **GIVEN** an approved application `ops-runner` and a `runner.yaml` pointing at its private key file
- **WHEN** an operator starts `keepiq-runner run --once`
- **THEN** the runner MUST obtain a bearer token through `POST /api/v1/token`
- **AND** no request body or log line MUST contain the private key or a secret value

### Requirement: Rotation proves the new value before Keepiq records it

For each configured rotation, the runner MUST generate a new value locally, record it in a local journal encrypted to the application's own key, set it at the target, log in to the target with it, and only then write it back through `PUT /api/v1/app/secrets/{id}` with `If-Match` set to the ETag it read. When the login fails, the runner MUST set the old value back at the target and MUST leave Keepiq unchanged.

#### Scenario: Weekly database password rotation

- **GIVEN** application `ops-runner` owns secret `pg-app-password` and a rotation for it with the `postgres` connector and schedule `0 3 * * 0`
- **WHEN** the schedule fires
- **THEN** the PostgreSQL role MUST accept the new password and refuse the old one
- **AND** `GET /api/v1/app/secrets/by-name/pg-app-password` MUST return an envelope whose decrypted value is the new password

#### Scenario: Failed proof keeps the old credential

- **GIVEN** a rotation whose target accepts the change but refuses the login with the new value
- **WHEN** the rotation runs
- **THEN** the target MUST be set back to the old value
- **AND** the secret in Keepiq MUST keep its previous ciphertext and ETag

### Requirement: A crashed rotation is completed from the journal

When the runner starts and finds a journal entry, it MUST decrypt it with the application key and retry the write-back. After a successful write-back it MUST remove the entry. The journal MUST hold ciphertext only.

#### Scenario: Power loss after the target changed

- **GIVEN** the runner set a new value at the target and stopped before the write-back
- **WHEN** the runner starts again
- **THEN** it MUST write the journalled value back to Keepiq and remove the journal entry

### Requirement: Concurrent changes are never overwritten

When the conditional write-back answers 412, the runner MUST set the old value back at the target, MUST NOT retry the write, and MUST report a conflict naming the secret.

#### Scenario: Human changed the secret during rotation

- **GIVEN** a rotation read `pg-app-password` with ETag `A` and another client updated it to ETag `B`
- **WHEN** the runner writes back with `If-Match: A`
- **THEN** the server MUST answer 412
- **AND** the runner MUST restore the old value at the target and log a conflict for `pg-app-password`

### Requirement: Rotations run on schedule or ahead of expiry

A rotation MUST run when its cron schedule fires, and, when `followExpiry` is set, when the envelope's `expiresAt` falls within the configured lead time.

#### Scenario: Expiry triggers a rotation

- **GIVEN** a rotation with `followExpiry` and a lead time of 7 days, and a secret whose `expiresAt` is in 5 days
- **WHEN** the runner checks its rotations
- **THEN** it MUST rotate that secret

### Requirement: Sync pushes changed secrets to cloud secret stores

For each configured sync set, the runner MUST poll `GET /api/v1/app/secrets?updated_since=<last poll>`, decrypt each changed secret in the set, and push it to the destination: AWS Secrets Manager, Azure Key Vault, GitHub Actions secrets (encrypted as a sealed box with the repository public key) or an `exec` hook. It MUST keep per destination entry only the ETag last pushed, MUST NOT push an unchanged secret again, and MUST retry a failed push with backoff without blocking other entries.

#### Scenario: Rotated key reaches AWS

- **GIVEN** a sync set with secret `stripe-key` and destination `aws-secrets-manager` with prefix `prod/`
- **WHEN** `stripe-key` is updated in the application vault
- **THEN** AWS Secrets Manager secret `prod/stripe-key` MUST hold the new value within one sync interval

#### Scenario: GitHub secret is sealed for the repository

- **GIVEN** a sync set with destination `github-actions` for repository `example/app`
- **WHEN** the runner pushes `deploy-token`
- **THEN** the request to GitHub MUST carry the value encrypted with the repository public key
