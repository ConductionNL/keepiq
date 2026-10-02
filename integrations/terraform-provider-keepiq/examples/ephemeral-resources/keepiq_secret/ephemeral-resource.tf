ephemeral "keepiq_secret" "db" {
  name = "db-password"
}

# Hand the value to another provider; it never reaches plan or state.
provider "postgresql" {
  host     = "db.internal"
  username = "app"
  password = ephemeral.keepiq_secret.db.value
}
