# Tasks: publish the Terraform provider to both registries

## 1. Registries

- [ ] 1.1 An organisation admin creates `ConductionNL/terraform-provider-keepiq` with the deploy key and the GPG signing key that `.github/workflows/integrations-terraform.yml` expects (keepiq#1041). Verify: the workflow's mirror step succeeds on a test tag.
- [ ] 1.2 Register the mirror in the Terraform Registry and the OpenTofu Registry as `conductionnl/keepiq`, then push the first `tf-v*` tag. Verify: `terraform init` and `tofu init` resolve and verify version 0.1.0 in a clean directory.

## 2. Docs

- [ ] 2.1 Replace the local-build install steps in `integrations/terraform-provider-keepiq/README.md` with the registry source. Verify: the README's example runs against the published version.

## Acceptance criteria

- `terraform init` and `tofu init` download and verify the signed provider from `conductionnl/keepiq`.
