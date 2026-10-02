# terraform-provider-keepiq

Manage Keepiq application secrets as code with Terraform 1.11+ or OpenTofu.
Values are decrypted and encrypted in the provider and never written to plan or state.

- `ephemeral "keepiq_secret"` reads a value for one run.
- `resource "keepiq_secret"` writes values through write-only arguments; bump `value_wo_version` to write again.
- `data "keepiq_secret_metadata"` returns timestamps, expiry and fingerprint, never a value.

Reference: [docs/](docs/). User guide: `docs/terraform.md` in the Keepiq repository.

## Develop

```sh
go test ./...                                   # unit tests
KEEPIQ_TF_BIN=$(which terraform) go test ./...  # plus the end-to-end test against Terraform 1.11+
scripts/docs.sh "$(which terraform)"            # regenerate docs/
```

This directory is the source of truth. Tag `tf-vX.Y.Z` in ConductionNL/keepiq to
release: the workflow copies it to the mirror repository
ConductionNL/terraform-provider-keepiq, which the registries read. Do not change
the mirror by hand.
