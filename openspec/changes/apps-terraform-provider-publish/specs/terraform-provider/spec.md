## MODIFIED Requirements

### Requirement: The provider is published to both registries

A tag `tf-v<version>` MUST publish a GPG-signed provider release that the Terraform Registry and the OpenTofu Registry serve as `conductionnl/keepiq`.

#### Scenario: Engineer installs the provider

- **GIVEN** release `tf-v0.1.0` is published
- **WHEN** an engineer runs `terraform init` with `source = "conductionnl/keepiq"` and version `0.1.0`
- **THEN** Terraform MUST download and verify the signed provider

#### Scenario: Engineer installs the provider with OpenTofu

- **GIVEN** release `tf-v0.1.0` is published
- **WHEN** an engineer runs `tofu init` with `source = "conductionnl/keepiq"` and version `0.1.0`
- **THEN** OpenTofu MUST download the provider from the OpenTofu Registry and verify its signature
