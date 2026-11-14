# Design: built-in item types with their own fields

## Where the definitions live

`src/utils/builtinTypeFields.js` holds one entry per built-in type: the value field's label and input, the extra fields, and the generic fields the type leaves out. The admin-defined types keep their definitions in `oc_keepiq_secret_types.fields`. The built-in ones stay in code for two reasons:

- A label stored in the database cannot be translated. A built-in field needs a fixed name in the data and a translated label on screen.
- `SeedSecretTypes` deliberately leaves existing types untouched, so storing fields there would need a migration for every installation that already has them.

The extra fields use the same shape as admin-defined fields (`{key, label, kind, required}`), plus a `displayLabel`. `label` stays the member name in the encrypted additional fields, so `splitTypedValues`, `mergeTypedValues` and `TypedFieldsForm` handle both without a second code path.

## The certificate stays in `key`

The certificate inventory parses `key` (`src/store/modules/certificate.js`), the expiry scan reads the `expires_at` that parse sets, and the guided renewal replaces the value. Moving the certificate into an additional field would break all three, and integriq's broker mints certificate secrets the same way. So the value field is relabelled "Certificate" and becomes a text area; there is no separate required secret.

## Multi-line fields are not masked

A password input drops line breaks, so a PEM block cannot go in one. A text area cannot be masked natively, so the multi-line fields (Certificate, Private key, Public key, Note) show what is typed. The fill page summary still masks the private key, as it masks every field whose name says key.

## The fill page needs the type

The fill page only receives field names. To label `key` as "Certificate" and render text areas, the public request answer adds `secret_type`, the name of the target secret's type. A secret's type is already plaintext metadata, and the requested field names already say as much, so no new secret is disclosed.
