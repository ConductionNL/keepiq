# Design: hidden custom fields and a real SSH key item

## Context

At development `156cd800`:

- `src/components/AdditionalFieldsEditor.vue:50` renders each value in a plain `NcTextField`; the field model is `{name, value}`.
- `src/store/modules/secret.js:313-321` decrypts `additionalFields` from JSON and `:377` encrypts it before `POST /api/v1/secrets` (`appinfo/routes.php:89`); the server never sees the shape.
- `src/components/SecretDetailSidebar.vue:570-577` renders each additional field value as plain `<dd>` text.
- `lib/Repair/SeedSecretTypes.php:64` seeds `ssh_key`; `src/dialogs/SecretCreateDialog.vue:95-108` gives it the generic key field.
- `src/cxf/cxf.js:205,355` maps CXF `ssh-key` items and custom fields to and from secrets.

## Goals / Non-Goals

**Goals**
- A value that must not be shown by default can be marked hidden and stays hidden until asked for.
- An SSH key pair is one item with its own fields, and a pair can be generated without leaving the browser.

**Non-Goals**
- Linked or file field kinds.
- Serving the key over an SSH agent; that is `clients-ssh-agent`.
- A server-side key generator.

## Decisions

### D1: The kind lives in the encrypted blob

Adding a `kind` property to each entry of the encrypted additional fields needs no migration and no server change, and an old blob reads as text. A separate column would leak which fields are hidden.

### D2: Generate in the browser

The private key is created with WebCrypto in the create dialog and encrypted with the item, so it is never in plain text on the server. Ed25519 is used where `crypto.subtle.generateKey` supports it, otherwise RSA 4096.

### D3: Fingerprint is derived, not typed

A SHA256 fingerprint is computed from the public key on save so it can never disagree with the key.
