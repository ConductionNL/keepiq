---
kind: code
---

# Built-in item types with their own fields

## Why

Every built-in item type except Card and Identity gets the same form: one masked "Secret value" field, a login and a URL. Even a Login calls its password "Secret value", and the create dialog does not mark that field as required although Create stays disabled without it. A certificate is a multi-line PEM block, so pasting it into a single-line password field breaks it, and there is nowhere to keep its private key or passphrase. An API key often comes with a secret, and a database connection needs a host, port and database name next to its password. The admin-defined item types (`admin-secret-types`) already carry their own fields, but the built-in types are seeded with a name and label only (`lib/Repair/SeedSecretTypes.php`), so they get none.

A request for a new secret follows the picked type (`secret-requests`), so the same gap shows there: a certificate request asks for a "Key / password", and the fill page offers a one-line input for it.

## What Changes

- Give Certificate, API Key, Database, SSH Key and Secure Note their own form: a value field with the type's own label and input, the type's extra fields, and the generic Login or URL field left out where the type has its own or, for a note, no use for it.
- Call a Login's value "Password", and mark the value field as required in the create dialog for every type.
- Keep the extra values in the encrypted additional fields under a fixed English name, and show them with a translated label. The type definitions live in the web app, not in the database, so their labels can be translated and existing installations need no migration.
- Keep the certificate itself in the secret's value (`key`), relabelled "Certificate", so the certificate inventory, expiry reminders and renewal (`certificate-lifecycle`) keep reading it from there.
- In the request dialog, tick and lock the fields a type requires. On the fill page, show the requested fields with the type's labels and inputs; the public request answer names the secret's type for this.

SSH Key gets a multi-line private key, a public key and a passphrase, so a pasted OpenSSH key keeps its line breaks. Generating a key pair, importing one from a file and the fingerprint stay with `vault-custom-field-kinds-and-ssh-key`, which builds on these fields.

## Capabilities

### New Capabilities

- `builtin-type-fields`

### Modified Capabilities

- `secret-requests`: a fresh request ticks the fields its type requires, instead of none.

## Impact

- **Frontend**: a new `src/utils/builtinTypeFields.js`, `TypedFieldsForm.vue` (a multi-line kind), `SecretCreateDialog.vue`, `SecretEditDialog.vue`, `SecretDetailSidebar.vue`, `SecretRequestCreateDialog.vue`, `SecretRequestFill.vue`.
- **Backend**: `SecretRequestFillController::show()` adds the secret's type name to the public request answer. No migration, no new route.
- **Security**: the type name is plaintext metadata on a secret already (`secret-requests`, Requestable Fields). The extra values are encrypted in the browser like every other additional field.
- **l10n**: new labels in every locale the app ships.
