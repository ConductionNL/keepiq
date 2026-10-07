---
kind: code
---

# Terraform provider

## Why

Teams that manage infrastructure as code cannot declare Keepiq secrets or applications in Terraform or OpenTofu. They copy values by hand or script the machine API. The usual provider pattern also puts secret values in Terraform state, which a zero-knowledge vault must avoid.

| Row | Capability | What keepiq does today |
|---|---|---|
| apps-22 | Manage secrets as code with a Terraform provider | No Terraform provider exists for managing keepiq secrets/applications as code. |

Matrix: keepiq `openspec/parity/capabilities.json`

### Demand

No demand row.

### Competitors rated yes

- Bitwarden: "bitwarden/clients@web-v2026.9.0 bitwarden_license/bit-web/src/app/secrets-manager/integrations/integrations.component.ts:101 Terraform Provider (registry.terraform.io/providers/bitwarden/bitwarden-secrets) Note: Terraform provider listed in product; its code is in a separate repo. Docs rating kept."
- 1Password: "https://developer.1password.com/docs/terraform : reference, create or update items as Terraform resources"
- Keeper: "https://docs.keeper.io/keeperpam/secrets-manager/integrations/terraform : Terraform Provider for Keeper Secrets Manager"
- HashiCorp Vault: "hashicorp/vault@v2.1.1 vault/logical_system_paths.go:2899 OpenAPI spec the provider tooling builds on | docs: https://developer.hashicorp.com/vault/docs/secrets/kv/kv-v2 (Terraform provider is the separate hashicorp/terraform-provider-vault repo) ..."

## What Changes

- A Terraform and OpenTofu provider `keepiq` in `integrations/terraform-provider-keepiq/`, built on the plugin framework and on `sdk/go/`.
- An ephemeral resource `keepiq_secret` that reads and decrypts an application secret for one run and never writes it to plan or state.
- A resource `keepiq_secret` that manages a secret in the application's vault. Its value is a write-only argument (`value_wo`, with `value_wo_version`), encrypted by the provider to the application's key; state keeps metadata only.
- A data source `keepiq_secret_metadata` for id, timestamps, expiry and fingerprint, without the value.
- Resources `keepiq_application` and `keepiq_application_lease_policy` that register, approve and configure applications through the public admin API.
- `terraform destroy` on a `keepiq_secret` removes it from state and warns, because the machine API has no delete by design.
- Releases signed and published to the Terraform and OpenTofu registries through a mirror repository named `terraform-provider-keepiq`.
- No change to the Keepiq server.

## Capabilities

### New Capabilities

- `terraform-provider`: manage Keepiq application secrets and applications as code, with secret values kept out of Terraform plan and state.

### Modified Capabilities

None.

## Impact

- **Backend**: none. Secrets go through the machine API; applications through the admin API from change `admin-public-api`.
- **Frontend**: none.
- **Database**: none.
- **Security**: values are decrypted and encrypted only in the provider process. Ephemeral resources and write-only arguments keep them out of plan and state files. The application private key and the admin app password are sensitive provider arguments, read from the environment by default.
- **Cross-app**: depends on `sdk/go/` (change `apps-client-libraries-and-ci`) and on `/api/v1/admin/applications` (change `admin-public-api`).
