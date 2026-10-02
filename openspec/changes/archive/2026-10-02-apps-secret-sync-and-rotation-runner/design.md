# Design: secret rotation and sync runner

## Context

Read at development `4c214a9d`.

- Rotation today is reminders and a proof check: `openspec/specs/rotation-expiry-policies/spec.md` ("Proven mark-rotated flow") closes a flag only when `key_updated_at` advanced; `lib/Controller/RotationController.php` and `src/store/modules/rotation.js` serve it to users. Nothing changes a credential at its target.
- The machine API: token exchange `appinfo/routes.php:299`; list, by-id, by-name, create and update at `:304` to `:309`. `lib/Controller/ApplicationSecretsController.php:329` `update()` replaces ciphertext through `lib/Service/SecretService.php` `updateByApplication()`, which scopes to the application's own vault, advances `key_updated_at` when the key changes, snapshots the previous version and audits `SECRET_UPDATED` with the application as actor. It has no precondition: a write based on a stale read silently wins.
- `lib/Service/MachineSecretEnvelopeService.php:129` `serialize()` returns `format`, `secret` (id, name, url, folderPath, type, createdAt, updatedAt, keyUpdatedAt), `encryption` and `ciphertext`. `expiresAt` exists on the entity (`lib/Db/Secret.php:173`) but not in the envelope.
- `lib/Service/MachineSecretResponseService.php:98` handles `If-None-Match` for reads.
- The machine API has no delete by design (`openspec/specs/secret-store-api/spec.md`, "Application Write-Back").
- `cli/` is a stdlib-only single binary (`cli/README.md`); `sdk/go/` is introduced by change `apps-client-libraries-and-ci`.
- `git grep -i 'aws|azure|key vault' lib src` finds nothing.

## Goals / Non-Goals

**Goals:**

- A credential in an application vault is rotated at its target on a schedule, with proof that the new value works before Keepiq records it.
- Secrets in an application vault reach AWS Secrets Manager, Azure Key Vault and GitHub Actions secrets and stay in sync.
- The Keepiq server never sees plaintext and needs no key.

**Non-Goals:**

- Rotating secrets in user vaults. Only an application's own key can decrypt its vault; a user vault needs the user's master password, which never leaves their client.
- Giving humans a readable copy of a rotated value. Application vault values are readable only by the application (write without read). A team that needs a human copy points an `exec` destination at its own process.
- Deleting at the destination when a Keepiq secret is deleted. The machine API cannot see deletions; the runner reports destination entries it no longer finds in Keepiq.
- More connectors in v1 (LDAP, Active Directory, GCP, Vercel). The `exec` hook covers them until a native connector follows.

## Decisions

### D1: A separate runner binary, not a CLI mode

`integrations/runner/` is a Go module on `sdk/go/`, producing `keepiq-runner` and image `ghcr.io/conductionnl/keepiq-runner`. It runs as a daemon or once (`keepiq-runner run --once`) for cron and Kubernetes CronJobs.

Alternative considered: a `keepiq runner` mode in the CLI. Rejected: database drivers and cloud SDKs would end the CLI's stdlib-only, dependency-free build that its README and spec promise.

### D2: Configuration names Keepiq secrets, never values

`runner.yaml` holds the Keepiq URL, application id and private key file, then `rotations` (secret name and folder, connector, target address, the Keepiq secret holding the admin credential for the target, cron schedule, `followExpiry`, generator length and character classes) and `syncs` (secret names, destination, destination address, the Keepiq secret holding destination credentials, or ambient cloud identity). Every credential the runner needs is itself a secret in the same application vault.

### D3: Rotation is prove-then-record, with a journal

For one rotation:

1. Read the secret and its ETag.
2. Generate the new value with `crypto/rand`.
3. Append to the local journal the new value encrypted to the application's own public key, plus the secret id and ETag. The journal holds ciphertext only.
4. Set the new value at the target with the admin credential (`ALTER ROLE ... PASSWORD` for PostgreSQL, `ALTER USER ... IDENTIFIED BY` for MySQL, or the `exec` hook with current and new values on stdin as JSON).
5. Log in to the target with the new value.
6. `PUT /api/v1/app/secrets/{id}` with the new ciphertext and `If-Match` set to the ETag from step 1.
7. Remove the journal entry.

If step 5 fails, the runner sets the old value back at the target and leaves Keepiq unchanged. If step 6 fails or the process dies after step 4, the next start decrypts the journal entry and retries the write-back. On 412 the value changed in Keepiq during the rotation; the runner sets the old value back at the target and reports a conflict.

Alternative considered: write the new value to Keepiq first, then change the target. Rejected: until the target accepts it, every consumer polling Keepiq would read a password that does not work yet.

### D4: When a rotation is due

A rotation runs when its cron schedule fires, or, with `followExpiry`, when the envelope's `expiresAt` is within the configured lead time. Because the write-back advances `key_updated_at`, any open rotation flag on that secret meets the existing proof rule.

### D5: Sync polls `updated_since` and pushes on change

Every sync interval (default 60 seconds) the runner lists the application's secrets with `updated_since`, fetches each changed secret in a sync set, decrypts it, and pushes it: `PutSecretValue` (creating on first sync) for AWS Secrets Manager, `SetSecret` for Azure Key Vault, and the GitHub REST secrets API for repository, environment or organisation secrets, encrypted with the repository public key as a libsodium sealed box as GitHub requires. The state directory keeps, per destination entry, the ETag last pushed, never a value. A failed push is retried with backoff and never blocks other entries.

### D6: Two additive changes to the machine API

`PUT /api/v1/app/secrets/{id}` accepts `If-Match`; when it does not match the current strong ETag the server answers 412 and changes nothing. A write without `If-Match` behaves as today. The envelope's `secret` block gains `expiresAt` (ISO 8601 or null). Both are additive to `doriath-machine-secret-v1`, so existing consumers keep working, and the discovery document advertises `conditionalWrite: true` and `expiresAt: true`.

## Security and zero-knowledge

- The server never sees plaintext or the application private key. Rotation writes back ciphertext produced in the runner; sync reads ciphertext and decrypts in the runner.
- Encrypted: the write-back value (RSA to the application key), the journal entries (same), everything on the wire to Keepiq. Plain, outside Keepiq: the value in the runner's memory, at the target, and at the destination, which is the purpose of rotation and sync.
- Plain in the state directory: secret ids, destination names and ETags only.
- Every rotation is audited on the server as `SECRET_UPDATED` by the application, with the previous version kept by version history, so an administrator can see and roll back a rotation.
- The runner's host holds the application key; the docs recommend one application per runner with only the secrets it rotates or syncs.

## Risks / Trade-offs

- A bug in a connector can lock a service out. The prove-then-record order and the automatic set-back on a failed login keep the old credential working until the new one is proven.
- Sync copies plaintext into another system with its own access model. That is the request; the docs state it, and the runner pushes only secrets named in a sync set.
- A cloud SDK per destination grows the runner. They stay in `integrations/runner/go.mod`, away from the CLI and the libraries.

## Seed data

None in the app. The runner's tests start PostgreSQL and MySQL containers and a stub Keepiq server serving the shared vectors from `sdk/testdata/`; cloud destinations are tested against local emulators (LocalStack for AWS, an httptest stub for Azure and GitHub).

## Migration

None. `expires_at` already exists; the server changes are code only. `<version>` in `appinfo/info.xml` does not need a bump for schema reasons.
