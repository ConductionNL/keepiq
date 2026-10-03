# terraform-provider-keepiq

Manage Keepiq application secrets as code with Terraform 1.11+ or OpenTofu.
Values are decrypted and encrypted in the provider and never written to plan or state.

- `ephemeral "keepiq_secret"` reads a value for one run.
- `resource "keepiq_secret"` writes values through write-only arguments; bump `value_wo_version` to write again.
- `data "keepiq_secret_metadata"` returns timestamps, expiry and fingerprint, never a value.
- `resource "keepiq_application"` registers an application from a CSR and approves it, through Keepiq's admin API. Destroy deletes its vault, so it needs `allow_vault_deletion = true`.
- `resource "keepiq_application_lease_policy"` sets an application's lease TTL override.

The secret resources authenticate as an application (`application_id`, `private_key`). The application resources authenticate as a Nextcloud user with an app password (`admin_user`, `admin_password`); give that user only the "Applications and machine access" admin area.

Reference: [docs/](docs/). User guide: `docs/terraform.md` in the Keepiq repository.

## Develop

```sh
go test ./...                                   # unit tests
KEEPIQ_TF_BIN=$(which terraform) go test ./...  # plus the end-to-end test against Terraform 1.11+
KEEPIQ_LIVE_URL=https://cloud.example/index.php KEEPIQ_ADMIN_USER=svc KEEPIQ_ADMIN_PASSWORD=... go test ./...  # plus the admin API against a live Keepiq
scripts/docs.sh "$(which terraform)"            # regenerate docs/
```

This directory is the source of truth. Tag `tf-vX.Y.Z` in ConductionNL/keepiq to
release: the workflow copies it to the mirror repository
ConductionNL/terraform-provider-keepiq, which the registries read. Do not change
the mirror by hand.
