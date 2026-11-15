---
kind: code
---

# Publish the Terraform provider to both registries

## Why

The Terraform provider is written and tested in `integrations/terraform-provider-keepiq/` (secret, ephemeral secret, metadata data source, application and lease policy), and `.github/workflows/integrations-terraform.yml` mirrors it on a `tf-v*` tag. It is not published: `terraform init` cannot resolve `conductionnl/keepiq`, because the mirror repository and the registry entries need an organisation admin. The archived change `2026-10-04-apps-terraform-provider` moved that step to keepiq#1041, so no open change tracks it.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `apps-22` | Manage secrets as code with a Terraform provider. | `building`: the provider works from a local build; it is in neither registry |

## What Changes

- **A published provider.** An organisation admin creates `ConductionNL/terraform-provider-keepiq` with a deploy key and GoReleaser signing, and registers it in the Terraform Registry and the OpenTofu Registry. The first `tf-v*` tag then publishes a signed release.
- **OpenTofu named in the requirement.** The publish requirement gains a scenario for `tofu init`.

## Capabilities

### Modified Capabilities

- `terraform-provider`: an OpenTofu install scenario.

## Impact

No change to the Keepiq app or its API.
