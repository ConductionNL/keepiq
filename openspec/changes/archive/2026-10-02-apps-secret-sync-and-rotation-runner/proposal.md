---
kind: code
---

# Secret rotation and sync runner

## Why

Keepiq reminds people to rotate and lets them mark a secret rotated, but it never changes a password at the database or service itself, and it never pushes a secret to a cloud secret store. The server cannot do either: it never sees plaintext. This change puts both jobs in a runner that holds an application's private key.

| Row | Capability | What keepiq does today |
|---|---|---|
| apps-20 | Rotate a database or service password automatically | keepiq flags stale/expiring secrets and lets a user mark one rotated, but it never rotates a password at the destination service itself the way Vault/1Password-style rotation connectors do. |
| apps-25 | Push secrets out to cloud secret stores such as AWS Secrets Manager, Azure Key Vault or GitHub and keep them in sync | No push of secrets to cloud secret stores. |

Matrix: keepiq `openspec/parity/capabilities.json`

The matrix recorded apps-20 as built; the decision corrected that: the evidence shows only reminders and a manual mark-rotated flow, so the state before this change is none.

### Demand

No demand row.

### Competitors rated yes

- Keeper (apps-20): "https://docs.keeper.io/keeperpam/privileged-access-manager/password-rotation/rotation-overview : scheduled rotation of database, AD, cloud and machine credentials via the Keeper Gateway (KeeperPAM / rotation add-on)"
- HashiCorp Vault (apps-20): "hashicorp/vault@v2.1.1 builtin/logical/database/path_creds_create.go:57 static-creds/<name>; builtin/logical/database/path_rotate_credentials.go:21 rotate-root, :48 rotate-role Note: Static roles rotate a database user's password on a period or schedule, with manual rotate endpoints."
- Keeper (apps-25): "https://docs.keeper.io/keeperpam/privileged-access-manager/universal-secrets-sync : 'Synchronize Shared Secrets to Cloud Secret Management Services'; Universal Secrets Sync automatically pushes shared secrets to cloud secret stores (KeeperPAM)."
- HashiCorp Vault (apps-25): "hashicorp/vault@v2.1.1 ui/lib/sync/addon/routes.js:10 destinations and sync routes; ui/lib/sync/addon/utils/constants.ts:12 aws-sm, azure-kv, gcp-sm, gh, vercel-project; ... | docs: https://developer.hashicorp.com/vault/docs/sync ..."

## What Changes

- A runner, `keepiq-runner`, in `integrations/runner/`, released as a static binary and a container image. It authenticates as one Keepiq application and works only on that application's vault through the machine API under `/api/v1/app/*`.
- **Rotation**: on a cron schedule or when a secret nears its expiry date, the runner generates a new password locally, sets it at the target (PostgreSQL, MySQL, or any system through an `exec` hook), logs in with it to prove it works, and writes it back to Keepiq encrypted to the application's own key.
- A local recovery journal holds the new value encrypted to the application's key between the target change and the write-back, so a crash never loses the only copy.
- **Sync**: the runner polls `updated_since`, decrypts changed secrets, and pushes them to AWS Secrets Manager, Azure Key Vault, GitHub Actions secrets or an `exec` hook.
- Two additive server changes to the machine API: `PUT /api/v1/app/secrets/{id}` honours `If-Match` and answers 412 on a mismatch, and the envelope carries `expiresAt`.

## Capabilities

### New Capabilities

- `secret-rotation-runner`: a runner outside the server that rotates credentials at their target and pushes secrets to cloud secret stores, decrypting only with an application's own key.

### Modified Capabilities

- `secret-store-api`: conditional write-back with `If-Match`, and the secret's expiry date in the machine envelope.

## Impact

- **Backend**: `ApplicationSecretsController::update()` and `SecretService::updateByApplication()` check `If-Match`; `MachineSecretEnvelopeService::serialize()` adds `expiresAt`; the discovery document advertises both.
- **Frontend**: none.
- **Database**: none. `expires_at` already exists on `keepiq_secrets`.
- **Security**: the server keeps receiving and returning ciphertext only. Plaintext exists in the runner's memory, at the rotation target and at the sync destination, all outside the Keepiq server and under the application owner's control.
- **Cross-app**: the runner builds on `sdk/go/` from change `apps-client-libraries-and-ci`. OpenConnector and other machine consumers see rotated values through the existing ETag and `updated_since` polling.
