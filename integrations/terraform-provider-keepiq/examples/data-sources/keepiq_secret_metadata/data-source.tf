data "keepiq_secret_metadata" "db" {
  name = "db-password"
}

output "db_password_expires_at" {
  value = data.keepiq_secret_metadata.db.expires_at
}
