# Design: custom fields with a schema and a real SSH key item

## Context

At development `156cd800`:

- `src/components/AdditionalFieldsEditor.vue:50` renders each value in a plain `NcTextField`; the field model is `{name, value}`.
- `src/store/modules/secret.js:313-321` decrypts `additionalFields` from JSON and `:377` encrypts it before `POST /api/v1/secrets` (`appinfo/routes.php:89`); the server never sees the shape.
- `src/components/SecretDetailSidebar.vue:570-577` renders each additional field value as plain `<dd>` text.
- `lib/Repair/SeedSecretTypes.php:64` seeds `ssh_key`; `src/dialogs/SecretCreateDialog.vue:95-108` gives it the generic key field.
- `src/cxf/cxf.js:205,355` maps CXF `ssh-key` items and custom fields to and from secrets.
- `lib/Service/MachineSecretEnvelopeService.php:153` hands the encrypted blob to applications as it is; they decrypt and read it themselves.
- `lib/Db/SecretRequest.php:129` stores the requested field names as plain JSON, which the fill page receives.

The custom-field decisions below were taken with Robert on the decision page "Typed Extra Fields" (2026-11-10).

## Goals / Non-Goals

**Goals**
- A custom field says what it holds, the way a JSON Schema property does, so the dialogs, the fill page and the sidebar can enter, check and show it properly.
- A value that must not be shown by default stays hidden until asked for, whatever its type.
- An SSH key pair is one item with its own fields, and a pair can be generated without leaving the browser.

**Non-Goals**
- Arrays, linked fields and file fields.
- Limits on objects (a schema for the object's own members).
- Serving the key over an SSH agent; that is `clients-ssh-agent`.
- A server-side key generator.

## Decisions

### D1: The schema lives in the encrypted blob, under `@schema`

The schemas are one extra member of the encrypted additional-fields blob, a map from field name to schema. This needs no migration and no server change, and sharing, federation, versions and offline copy the blob, so the schemas travel with every copy. A separate encrypted column was rejected: it would follow the path `passkeys` took through about 85 files. Turning every value into `{value, schema}` was rejected because it breaks every reader and every application. The cost: `@schema` is a reserved field name, every reader that lists fields skips it through one helper in `src/utils/additionalFields.js`, and the application API docs describe it.

### D2: JSON Schema vocabulary, presets on top

A schema uses the type, format and limit names of JSON Schema and OpenRegister's property editor (`EditSchemaProperty.vue`), so a field means the same in both apps. Nine presets cover the common cases; an Advanced part shows the full schema. Values stay strings in the blob, so no existing reader breaks on a number or an object.

### D3: Hidden is a switch, not a format

JSON Schema masks only strings, through `format: password`. A `hidden` flag on any type lets a JSON service account or a URL with a token start masked, and keeps how a value looks once shown apart from whether it starts masked, as the built-in fields already do. It is a Keepiq keyword next to the standard ones; the Password preset is a string with `hidden` on.

### D4: Refuse everywhere

No Keepiq screen stores a value that does not fit its schema: the dialogs and the fill page block, a schema change waits for the value to fit, and an import keeps a value that does not fit but drops its schema. The server cannot check, because it never sees the values, so the sidebar still shows a value written through the API that does not fit, as text with a warning.

### D5: Requests carry the schemas in plain text

The fill page can only enforce a schema it receives, so each requested field's schema is stored with the request, in a new nullable `field_schemas` column next to `requested_fields`. A separate column leaves the list of names as it is, so nothing that reads `requested_fields` changes. The column is plain text on the server, as the names already are, so the server learns a requested field's type, limits and whether it is hidden; it never learns the value. The schema is merged into the secret's `@schema` with the submitted value.

### D6: Built-in type fields use the same schemas

The fields of the built-in types (`src/utils/builtinTypeFields.js`, change `vault-builtin-type-fields`) describe themselves with the same schemas, so a Database's Port is an integer from 1 to 65535 and a certificate's private key a multi-line hidden string. The built-in definitions stay in code; only custom fields store a schema in the blob.

### D7: Generate the SSH key in the browser

The private key is created with WebCrypto in the create dialog and encrypted with the item, so it is never in plain text on the server. Ed25519 is used where `crypto.subtle.generateKey` supports it, otherwise RSA 4096.

### D8: The SSH fingerprint is derived, not typed

A SHA256 fingerprint is computed from the public key on save so it can never disagree with the key.
