---
kind: code
---

## Why

A secret owner can see their additional fields but has no way to add or change one.

Every layer supports them except the two dialogs an owner actually uses:

| Layer | Additional fields |
|---|---|
| `SecretController::create` / `update` | accepts `?string $additionalFields` |
| `SecretService::create` / `update` | stores and updates the blob |
| Store `createSecret` / `updateSecret` | encrypts `data.additionalFields` client-side |
| Store fetch | decrypts it back to an object |
| `SecretDetail.vue` | **displays** it — `v-for="(value, key) in secret.additionalFields"` |
| `SecretCreateDialog.vue` | **no references at all** — payload is `name`, `typeId`, `url`, `key`, `login`, `folderId` |
| `SecretEditDialog.vue` | **no references at all** — payload is `name`, `typeId`, `url`, `login` |

So the encrypted blob round-trips perfectly, is rendered read-only on the detail view, and can only be *written* by someone other than the owner:

- `WriteSecretForAppDialog` (writing into an application's vault)
- the share dialogs, which merely carry the blob through
- secret import
- **a secret-request fill — where an external recipient submits them**

That last one is how this surfaced. The request flow lets a requester name arbitrary members (`zgw-client-id` and the like) and the fill page stores them, so today **the only way an owner gets an additional field onto their own secret is to ask a stranger to put it there**, or to import it.

The omission is in the specification, not only the code. Nine specs discuss additional fields — secrets, secret-requests, secret-store-api, secret-import, cxf-import-export, honey-credentials, encryption-suites, secret-audit-trail, siem-audit-export — and `secrets-write-ui`, the one capability that owns creating and editing a secret, never mentions them. Both of its requirements enumerate the collectable fields explicitly and simply leave this one out, which is how a dialog that cannot write them passed review.

## What Changes

- **`SecretCreateDialog` gains additional fields**: add a named member, remove one, before the secret exists.
- **`SecretEditDialog` gains the same**, pre-filled from the decrypted blob so an existing member can be renamed, re-valued or removed.
- **Reserved names are refused** — `key`, `login` and `url` name real columns, so a member with one of those names would be silently misrouted or shadowed. The same rule the request dialog already applies to custom field names, for the same reason.
- **The blob is encrypted client-side as one unit**, exactly as the store already does. No change to the encryption boundary: `additional_fields` stays a single RSA-encrypted blob, and the server continues to receive only ciphertext.
- **The two `secrets-write-ui` requirements are corrected** to name additional fields among the fields these dialogs collect, so the spec stops describing a narrower surface than the data model.

## Capabilities

### New Capabilities

None. The data model, API, crypto and read surface all exist; this is the missing write surface.

### Modified Capabilities

- `secrets-write-ui`: both `Create a Secret from the UI` and `Edit a Secret from the UI` gain additional fields among the fields they collect, plus the reserved-name rule. These requirements currently enumerate the collectable fields and omit this one, which is the root of the gap.

## Impact

**Frontend only**
- `src/dialogs/SecretCreateDialog.vue` — add/remove named members; include the blob in the payload.
- `src/dialogs/SecretEditDialog.vue` — the same, pre-filled from the decrypted secret.
- Possibly a small shared component or composable: both dialogs need the same name/value editor, and the request dialog already has a near-identical "add a named field" control. One implementation is preferable to three.

**Not affected**
- No backend change. Both controller methods already accept `additionalFields`, and both service methods already persist it.
- No migration, no schema change.
- No change to the encryption boundary (ADR-003). The blob is encrypted in the browser as one unit; the server never sees a member name or value in plaintext, which is why per-member validation stays client-side.

**Interaction with `request-first-secret-requests` (#267)** — independent, but related in one way worth stating: the request dialog's `addCustomField` already implements the reserved-name refusal and the "name a member that does not exist yet" affordance. Reusing that logic rather than writing a second variant is the point of the shared-component note above.
