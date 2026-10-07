## 1. Machine API additions

- [x] 1.1 Honour `If-Match` in `ApplicationSecretsController::update()` and answer 412 on a mismatch without writing. Verify with PHPUnit tests in `tests/Unit/Controller/ApplicationSecretsControllerTest.php` for match, mismatch and absent header. Done: `checkIfMatch()` in `ApplicationSecretsController`; five PHPUnit tests (match, stale 412 with the current ETag and no write, absent, other vault 404, list/`*`/weak tag). Red before, green after.
- [x] 1.2 Add `expiresAt` to the envelope's `secret` block and advertise `conditionalWrite` and `expiresAt` in the discovery document. Verify with PHPUnit tests on `MachineSecretEnvelopeService` and `DiscoveryController`, and a new assertion in `tests/integration/machine-secret-api.postman_collection.json`. Done: `expiresAt` in `MachineSecretEnvelopeService::serialize()`, `conditionalWrite` and `expiresAt` in discovery; PHPUnit `testEnvelopeCarriesExpiresAt`, `testDocumentAdvertisesConditionalWriteAndExpiresAt`; Newman asserts both. The CLI fixture gains `expiresAt: null`.

## 2. Runner core

- [x] 2.1 Create module `integrations/runner/` on `sdk/go/` with config loading, daemon and `run --once` modes, and a structured log that never contains a value. Verify with Go tests for config validation and a log test that greps for the test value. Done: `integrations/runner/` (config refuses unknown keys, `run`, `run --once`, `check`), `internal/logx` redacts every value the runner handled; tests in `internal/config`, `internal/logx`, `internal/runner`.
- [x] 2.2 Implement the rotation procedure with generator, journal (ciphertext only), set, prove, conditional write-back, set-back on failed login, and recovery from the journal. Verify with Go tests that kill the process after the target change and assert the next start completes the write-back. Done: `internal/rotate`; `TestCrashAfterTargetChangeIsCompletedOnNextStart` stops a child process right after the target change and completes the write-back on the next start; set-back, 412 conflict and dropped-entry tests.
- [x] 2.3 Schedule rotations by cron and by `expiresAt` lead time. Verify with Go tests using a fake clock. Done: `rotate.Due`; `TestDueBySchedule`, `TestDueByExpiryLeadTime`, `TestDaemonWaitsForScheduleOrExpiry` with a fake clock.

## 3. Connectors and destinations

- [x] 3.1 Add the `postgres` and `mysql` rotation connectors. Verify with Go integration tests against PostgreSQL and MySQL containers that log in with the new password and fail with the old one. Done: `internal/connectors`; `TestPostgres` and `TestMySQL` passed against postgres:16-alpine and mysql:8.4 (new password logs in, old one refused). CI runs them with service containers.
- [x] 3.2 Add the `exec` connector and `exec` destination (JSON on stdin, exit code as result). Verify with Go tests using a script fixture. Done: exec connector and destination; `TestExecConnector`, `TestExecDestination` with script fixtures.
- [x] 3.3 Implement sync with `updated_since` polling, per-entry ETag state and backoff. Verify with Go tests against the stub server that a changed secret is pushed once and an unchanged one never. Done: `internal/syncer`; `TestChangedSecretIsPushedOnceAndUnchangedNever`, `TestFailedPushBacksOffWithoutBlockingOthers` against the keepiqtest stub.
- [x] 3.4 Add the `aws-secrets-manager`, `azure-key-vault` and `github-actions` destinations. Verify with Go tests against LocalStack and httptest stubs, including the sealed-box encryption for GitHub. Done: `internal/destinations`; tests against an httptest stub of the Secrets Manager JSON protocol, Azure token and Set Secret, and the GitHub API (the sealed box opens only with the repository key). Owed: a run against LocalStack.

## 4. Release

- [x] 4.1 Add `.github/workflows/integrations-runner.yml`: tests on pull requests, static binaries and a signed image on `runner-v*` tags. Verify with a dry run on a pull request. Done: `.github/workflows/integrations-runner.yml`. Owed: the dry run on the pull request and the first `runner-v*` release.
- [x] 4.2 Document setup, the application-per-runner advice and every connector on the docs site. Verify with the docs build in `docs/`. Done: `docs/rotation-and-sync.md`, `integrations/runner/runner.example.yaml`. Owed: the docs build.
- [ ] 4.3 (LIVE CHECK OWED: needs an application on the dev instance and a local PostgreSQL; not run here) Rotate a real credential end to end. Verify manually on the dev instance: an application owns `pg-app-password`, the runner rotates it at a local PostgreSQL, and `keepiq ci fetch pg-app-password` returns a value that logs in.

## Acceptance criteria

- A scheduled rotation changes the PostgreSQL password, proves the new one by logging in, and only then stores it in Keepiq.
- If the new password does not log in, the old one keeps working and Keepiq is unchanged.
- A crash between the target change and the write-back is repaired on the next start.
- A concurrent change in Keepiq makes the write-back fail with 412 and the runner restores the old target value.
- A changed secret in a sync set reaches AWS Secrets Manager, Azure Key Vault or GitHub within one sync interval.
- No request from the runner to Keepiq, and no log line or state file, contains a plaintext value.
