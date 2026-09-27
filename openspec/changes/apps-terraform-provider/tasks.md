## 1. Provider

- [ ] 1.1 Create module `integrations/terraform-provider-keepiq/` on `terraform-plugin-framework` and `sdk/go/`, with the provider configuration and environment defaults. Verify with `go test ./...` and a provider schema test.
- [ ] 1.2 Add the ephemeral resource `keepiq_secret` (by name and folder, or id). Verify with a `terraform-plugin-testing` test against the stub that the value is usable in a run and absent from plan and state.
- [ ] 1.3 Add the resource `keepiq_secret` with write-only value arguments, `value_wo_version`, fingerprint check, refresh and import. Verify with tests that create, update on version change, import, and assert no state file contains the value.
- [ ] 1.4 Make destroy of `keepiq_secret` remove from state with a warning naming the secret. Verify with a test on the diagnostics.
- [ ] 1.5 Add the data source `keepiq_secret_metadata`. Verify with a test that it exposes no value attribute.
- [ ] 1.6 Refuse `value_wo` on Terraform versions without write-only support, with a clear diagnostic. Verify with a test that sets an older client capability.

## 2. Applications

- [ ] 2.1 Add `keepiq_application` (register from CSR, approve, `allow_vault_deletion` guard) and `keepiq_application_lease_policy` on the admin API. Verify with stub tests for create, approve and a refused destroy, and an acceptance test when `KEEPIQ_LIVE_URL` is set.

## 3. Release and docs

- [ ] 3.1 Generate docs with `tfplugindocs` and add examples for each resource. Verify with a CI check that the generated docs are current.
- [ ] 3.2 Add `.github/workflows/integrations-terraform.yml`: tests on pull requests, and on `tf-v*` tags a push to the mirror repository. Verify with a dry run on a pull request.
- [ ] 3.3 Ask an organisation admin to create `ConductionNL/terraform-provider-keepiq` with a deploy key and GoReleaser signing, and register it in the Terraform and OpenTofu registries. Verify manually that `terraform init` resolves `conductionnl/keepiq` after the first tag.

## Acceptance criteria

- A configuration using `ephemeral "keepiq_secret"` passes a Keepiq value to another provider, and neither the plan file nor the state file contains that value.
- A `keepiq_secret` resource with `value_wo` creates a secret the application can decrypt, and changing `value_wo_version` updates it.
- `terraform destroy` leaves the secret in Keepiq and prints a warning naming it.
- An application can be registered and approved from Terraform with a CSR, and cannot be destroyed without `allow_vault_deletion`.
- A tagged release is installable with `terraform init` and `tofu init`.
