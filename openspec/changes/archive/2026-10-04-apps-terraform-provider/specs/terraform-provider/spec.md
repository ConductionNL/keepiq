## ADDED Requirements

### Requirement: Secret values never enter Terraform plan or state

The `keepiq` provider MUST decrypt and encrypt secret values only in its own process, and MUST expose secret values only through the ephemeral resource `keepiq_secret` and the write-only arguments of the resource `keepiq_secret`. No resource or data source attribute stored in plan or state MUST contain a secret value.

#### Scenario: Ephemeral read feeds another provider

- **GIVEN** application `infra` with secret `db-password` and a configuration that passes `ephemeral.keepiq_secret.db.value` to a database provider
- **WHEN** an engineer runs `terraform apply`
- **THEN** the database provider MUST receive the decrypted value
- **AND** neither the saved plan nor the state file MUST contain it

### Requirement: Managed secrets use write-only values

The resource `keepiq_secret` MUST accept `value_wo`, `login_wo` and `additional_fields_wo` as write-only arguments, MUST encrypt them to the application's key after checking the vault certificate fingerprint, and MUST create or update the secret through `POST /api/v1/app/secrets` or `PUT /api/v1/app/secrets/{id}`. An update of the value MUST happen only when `value_wo_version` changes. State MUST hold only id, metadata, ETag and `key_updated_at`.

#### Scenario: Engineer rotates a value by bumping the version

- **GIVEN** a `keepiq_secret` resource `api_token` with `value_wo_version = 1`
- **WHEN** the engineer sets a new `value_wo` and `value_wo_version = 2` and runs `terraform apply`
- **THEN** the application MUST decrypt the new value from its vault
- **AND** the state file MUST NOT contain the old or the new value

### Requirement: Destroy leaves the secret in Keepiq

Destroying a `keepiq_secret` resource MUST remove it from state only and MUST emit a warning naming the secret and stating that an administrator deletes it in Keepiq, because the machine API offers no deletion.

#### Scenario: Destroy warns instead of deleting

- **GIVEN** a managed `keepiq_secret` named `old-token`
- **WHEN** the engineer runs `terraform destroy`
- **THEN** the run MUST succeed with a warning naming `old-token`
- **AND** `old-token` MUST still exist in the application vault

### Requirement: Metadata without values

The data source `keepiq_secret_metadata` MUST return id, name, folder, timestamps, `expires_at` and certificate fingerprint for a secret, and MUST NOT offer a value attribute.

#### Scenario: Plan reacts to an expiry date

- **GIVEN** a data source `keepiq_secret_metadata` for `db-password`
- **WHEN** Terraform reads it
- **THEN** it MUST expose `expires_at` and `key_updated_at`
- **AND** it MUST expose no value, login or additional field

### Requirement: Applications are managed through the admin API

The resource `keepiq_application` MUST register an application from a CSR, approve it through `POST /api/v1/admin/applications/{id}/approve` with the configured admin app password, and export its id and certificate. Destroying it MUST fail unless `allow_vault_deletion` is true, because deleting an application deletes its vault.

#### Scenario: Pipeline application declared in code

- **GIVEN** a service account app password holding the "Applications and machine access" area and a CSR for `ci-runner`
- **WHEN** an engineer applies a `keepiq_application` resource for `ci-runner`
- **THEN** `ci-runner` MUST be approved in Keepiq
- **AND** the resource MUST export its certificate

#### Scenario: Accidental destroy is refused

- **GIVEN** a `keepiq_application` resource without `allow_vault_deletion`
- **WHEN** the engineer runs `terraform destroy`
- **THEN** the run MUST fail with a message that the application's vault would be deleted

### Requirement: The provider is published to both registries

A tag `tf-v<version>` MUST publish a GPG-signed provider release that the Terraform Registry and the OpenTofu Registry serve as `conductionnl/keepiq`.

#### Scenario: Engineer installs the provider

- **GIVEN** release `tf-v0.1.0` is published
- **WHEN** an engineer runs `terraform init` with `source = "conductionnl/keepiq"` and version `0.1.0`
- **THEN** Terraform MUST download and verify the signed provider
