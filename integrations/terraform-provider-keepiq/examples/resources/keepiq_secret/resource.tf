variable "api_token" {
  type      = string
  sensitive = true
  ephemeral = true
}

resource "keepiq_secret" "api_token" {
  name             = "api-token"
  url              = "https://api.example.org"
  value_wo         = var.api_token
  value_wo_version = 1 # bump to write a new value
}
