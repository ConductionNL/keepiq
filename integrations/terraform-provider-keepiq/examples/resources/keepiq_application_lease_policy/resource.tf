resource "keepiq_application_lease_policy" "ci_runner" {
  application_id = keepiq_application.ci_runner.id
  default_ttl    = 600  # seconds
  max_ttl        = 3600 # seconds
  # renewable left out: inherits the instance setting.
}
