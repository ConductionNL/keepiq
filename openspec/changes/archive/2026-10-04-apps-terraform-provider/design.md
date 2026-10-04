# Design: Terraform provider

## Context

Read at development `4c214a9d`.

- The machine API covers what a provider needs for secrets: token exchange (`appinfo/routes.php:299`), list, by-id, by-name, create and update (`:304` to `:309`), ETag reads (`lib/Service/MachineSecretResponseService.php:98`). There is no delete route on purpose (`openspec/specs/secret-store-api/spec.md`, "Application Write-Back", scenario "Machine deletion refused").
- `lib/Controller/ApplicationSecretsController.php:283` `create()` and `:329` `update()` accept fields already encrypted to the application's own certificate; the server validates shape only.
- Applications are registered and approved through session routes (`appinfo/routes.php:271` to `:286`); change `admin-public-api` adds `/api/v1/admin/applications` for scripts.
- `cli/internal/crypto/crypto.go` holds the Go crypto recipe; change `apps-client-libraries-and-ci` moves it to `sdk/go/` and adds encryption.
- `grep -rli terraform` over the repository finds nothing.
- Terraform stores every resource and data source attribute in plan and state files. Terraform 1.10 added ephemeral resources, which are never stored; Terraform 1.11 added write-only arguments, which are sent to the provider and never stored. OpenTofu added the same two features in its own releases; the provider docs state the tested minimum OpenTofu version.

## Goals / Non-Goals

**Goals:**

- Declare application secrets and applications in Terraform or OpenTofu.
- Keep every secret value out of plan and state.
- Publish in the registries where Terraform and OpenTofu users look.

**Non-Goals:**

- User vault secrets. The provider is a machine client; user vaults need a master password that never leaves the user's client.
- A data source that returns a value. Data source attributes are written to state; the ephemeral resource replaces it.
- Hard deletion of secrets (see D4).
- Terraform versions before 1.11. Older versions cannot keep a written value out of state; the provider refuses to plan a `value_wo` there with a clear message.

## Decisions

### D1: Source here, published through a mirror repository

The provider is a Go module at `integrations/terraform-provider-keepiq/` using `terraform-plugin-framework` and `sdk/go/`. The Terraform Registry only indexes public repositories named `terraform-provider-<name>` with GPG-signed releases. A workflow on tag `tf-v*` pushes the module to `ConductionNL/terraform-provider-keepiq`, where GoReleaser builds, signs and publishes the release; the registries pick it up from there. Creating that repository and its deploy key is a one-time organisation admin step.

Alternative considered: develop the provider only in its own repository. Rejected: the crypto recipe and its vectors live here, and the provider must fail CI in the same pull request that changes them.

### D2: Provider configuration

`url`, `application_id`, `private_key` (sensitive; defaults to `KEEPIQ_APP_KEY` or the file in `KEEPIQ_APP_KEY_FILE`) for secrets, and optionally `admin_username` and `admin_app_password` (sensitive; defaults to `KEEPIQ_ADMIN_USER` and `KEEPIQ_ADMIN_APP_PASSWORD`) for application resources. The provider discovers the instance and caches the bearer token for the run.

### D3: Values only through ephemeral reads and write-only arguments

- `ephemeral "keepiq_secret"` takes `name` and optional `folder`, or `id`, and returns `value`, `login` and `additional_fields`, decrypted in the provider. Terraform never persists them.
- `resource "keepiq_secret"` takes `name`, `folder`, `url`, and the write-only `value_wo`, `login_wo` and `additional_fields_wo`, plus `value_wo_version`. The provider encrypts the write-only values to the public half of the application key, after checking it against the vault's certificate fingerprint, and creates or updates the secret. A change of `value_wo_version` triggers an update. State holds `id`, metadata, `etag` and `key_updated_at`.
- Refresh reads the envelope. When `key_updated_at` moved outside Terraform (a rotation by the runner, for example), the provider records the new timestamp and reports no diff, because Terraform cannot know the value; `value_wo_version` stays the only trigger for a Terraform write.
- `data "keepiq_secret_metadata"` returns id, timestamps, `expires_at` and fingerprint, never a value.

Alternative considered: a classic `sensitive` value attribute. Rejected: `sensitive` hides a value in output but still writes it to state in plain form.

### D4: Destroy removes from state and warns

The machine API refuses deletion by design: a leaked five-minute bearer token must not be able to destroy credentials. On destroy, `keepiq_secret` is removed from state and the provider emits a warning naming the secret and saying that an administrator deletes it in Keepiq. Import (`terraform import keepiq_secret.x <id>`) adopts an existing secret.

### D5: Applications through the admin API

`keepiq_application` takes `name`, `description` and `csr_pem`, registers the application, approves it through `POST /api/v1/admin/applications/{id}/approve`, and exports `id` and `certificate_pem`. The private key stays with whoever made the CSR; the docs warn that generating it with `tls_private_key` stores it in state. Deleting an application deletes its vault, so destroy requires `allow_vault_deletion = true` on the resource and otherwise fails with an explanation. `keepiq_application_lease_policy` manages the lease TTL policy through the admin API.

### D6: Tests and docs

Unit tests run the provider against an httptest stub that serves the shared vectors from `sdk/testdata/`, with `terraform-plugin-testing`, and assert that no plan or state file contains the test value. Acceptance tests (`TF_ACC=1`) run against a real instance when `KEEPIQ_LIVE_URL` is set. Documentation is generated with `tfplugindocs` into the module's `docs/` folder, as the registry requires.

## Security and zero-knowledge

- The server never sees plaintext: the provider encrypts before `POST` or `PUT` and decrypts after reads, in its own process.
- Not stored anywhere by Terraform: secret values (ephemeral outputs and write-only arguments). Stored plain in state: ids, names, folders, URLs, timestamps, ETags, the application certificate. The private key and admin app password are provider arguments marked sensitive and taken from the environment by default.
- The test suite fails when a plan or state file contains the test value, so a regression that leaks a value into state cannot merge.

## Risks / Trade-offs

- Terraform 1.11, or an OpenTofu release with write-only arguments, is the minimum for managed values. Terraform 1.10 can still use the ephemeral read.
- Destroy does not delete the secret in Keepiq. The warning names it; the alternative would give a machine token deletion rights the API refuses on purpose.
- The mirror repository adds a release hop. The workflow is the only writer, and the mirror's README says changes go to this repository.

## Seed data

None in the app. The acceptance tests register their own application through the admin API on the test instance.

## Migration

None. No server change, so no table, column or `<version>` bump.
