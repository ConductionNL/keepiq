# Needs admin_user and admin_password on the provider (or KEEPIQ_ADMIN_USER
# and KEEPIQ_ADMIN_PASSWORD), for an account holding the Keepiq
# "Applications and machine access" admin area.
variable "ci_runner_csr" {
  type = string
}

resource "keepiq_application" "ci_runner" {
  name        = "ci-runner"
  description = "Build pipeline"
  csr_pem     = var.ci_runner_csr

  # Destroy deletes the application and its vault only when this is true.
  allow_vault_deletion = false
}

output "ci_runner_certificate" {
  value = keepiq_application.ci_runner.certificate_pem
}
