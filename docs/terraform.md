<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Terraform and OpenTofu

Declare your application's Keepiq secrets next to the infrastructure that uses them.
The `keepiq` provider decrypts and encrypts in its own process, and Terraform never stores a value in plan or state.
You need Terraform 1.11 or later, or an OpenTofu release with write-only arguments.

## Configure the provider

```hcl
terraform {
  required_providers {
    keepiq = { source = "conductionnl/keepiq" }
  }
}

provider "keepiq" {}
```

The provider reads `KEEPIQ_URL`, `KEEPIQ_APP_ID` and `KEEPIQ_APP_KEY` (or `KEEPIQ_APP_KEY_FILE`) from the environment, so no key sits in your configuration.
Set `KEEPIQ_APP_CERT_FILE` as well, and the provider refuses any secret encrypted to another certificate.

## Use a value without storing it

```hcl
ephemeral "keepiq_secret" "db" {
  name = "db-password"
}

provider "postgresql" {
  password = ephemeral.keepiq_secret.db.value
}
```

An ephemeral value exists for one run. Pass it to a provider block or a write-only argument.

## Manage a secret

```hcl
resource "keepiq_secret" "api_token" {
  name             = "api-token"
  value_wo         = var.api_token
  value_wo_version = 1
}
```

`value_wo`, `login_wo` and `additional_fields_wo` are write-only: Terraform sends them once and keeps nothing.
Because Terraform cannot compare a value it never stored, bump `value_wo_version` to write a new value.
A value rotated outside Terraform, by the rotation runner for example, shows no diff.

`terraform destroy` leaves the secret in Keepiq and warns with its name. An administrator deletes it in Keepiq.
`terraform import keepiq_secret.api_token <id>` adopts an existing secret; the next apply writes `value_wo`.

## Read metadata

`data "keepiq_secret_metadata"` gives the id, timestamps, `expires_at` and certificate fingerprint of a secret, never its value.

## Not yet available

Registering and approving applications from Terraform waits for the Keepiq admin API.
Until then, register the application in Keepiq and give the provider its key.

## Next step

Put the application key in `KEEPIQ_APP_KEY`, add the provider block, and run `terraform plan`.
