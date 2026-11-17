---
kind: code
---

# Hidden custom fields and a real SSH key item

## Why

A person can add named custom fields to an item, but every value is a plain text field and is printed unmasked on the detail sidebar (`src/components/AdditionalFieldsEditor.vue:50`, `src/components/SecretDetailSidebar.vue:570-577`). A recovery code or an API pin typed there is readable by anyone looking over a shoulder. The SSH Key type is seeded (`lib/Repair/SeedSecretTypes.php:64`) but the create dialog offers one value field for it (`src/dialogs/SecretCreateDialog.vue:95-108`), so a key pair cannot be stored as a key pair. Both rows sit in the vault area, the core area, with six and three competitors rating yes. They share one form (the create and edit dialogs) and one blob (the encrypted additional fields), so they are one change.

The rows share one screen or service, so they are one change.

### Matrix rows (`keepiq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `vault-11` | Add your own custom fields to an item, including hidden ones. | `partial`: `partial`: named custom fields exist and are stored encrypted, but there is no hidden kind, so every value is typed and shown as plain text |
| `vault-14` | Store an SSH key pair as its own item type. | `partial`: `partial`: an SSH Key type exists but holds one value field, with no separate private key, public key or fingerprint and no key pair generation |

### Demand

- `vault-11`: no demand row.
- `vault-14`: no demand row.

### Competitors rated yes

- `vault-11`, bitwarden: "bitwarden/clients@web-v2026.9.0 libs/common/src/vault/enums/field-type.enum.ts:4 Text, :5 Hidden, :6 Boolean, :7 Linked; libs/vault/src/cipher-form/components/custom-fields/custom-fields.component.ts:244 addField, :200 hidden fiel"
- `vault-11`, onepassword: "https://support.1password.com/custom-fields/ : 11 field types including Password ('copy, reveal, or enlarge')"
- `vault-11`, passbolt: "passbolt/passbolt_api@v5.16.0 config/Migrations/20250704120736_V530AddCustomFieldStandaloneResourceType.php, plugins/PassboltCe/ResourceTypes/src/Model/Entity/ResourceType.php:56 v5-custom-fields; passbolt/passbolt_styleguide@v5.1"
- `vault-11`, keeper: "https://docs.keeper.io/user-guides/web-vault#custom-fields : custom fields incl. 'Hidden Field', 'Security Question & Answer', 'Multi-line Text'"
- `vault-11`, hashicorp-vault: "hashicorp/vault@v2.1.1 ui/lib/core/addon/components/kv-object-editor.hbs:25 free key input per row, :36 MaskedInput for values when @isMasked; ui/lib/kv/addon/components/kv-create-edit-form.hbs:42 KvObjectEditor Note: A KV secret "
- `vault-11`, nextcloud-passwords: "marius-wieschollek/passwords@2026.9.0 src/vue/Dialog/CreatePassword/CustomFields/CustomFieldType.vue:14-19 types text, secret, email, url, file, data; src/vue/Dialog/CreatePassword.vue:132 up to 20 custom fields; src/vue/Dialog/Cr"
- `vault-14`, bitwarden: "bitwarden/server@v2026.9.1 src/Core/Vault/Enums/CipherType.cs:11 SSHKey = 5; bitwarden/clients@web-v2026.9.0 libs/vault/src/cipher-form/components/sshkey-section/sshkey-section.component.ts:55 privateKey field (public key and fing"
- `vault-14`, onepassword: "https://support.1password.com/item-categories/ : 'SSH Key: contain an option to generate new SSH keys or import existing'"
- `vault-14`, keeper: "https://docs.keeper.io/user-guides/record-types : 'SSH Key: SSH information, such as public and private key strings'"

## What Changes

- Give each custom field a kind: text, hidden or boolean. Hidden values render masked with a reveal and copy control, in the editor and on the detail sidebar.
- Give the SSH Key type its own form: private key, public key and fingerprint, with a Generate key pair action that runs in the browser (WebCrypto Ed25519 where the browser supports it, else RSA 4096) and an Import from file action.
- Keep the encrypted blob shape backward compatible: a field without a `kind` reads as text.
- Map the new fields through the CXF export and import in `src/cxf/cxf.js` so a hidden field and an SSH key round trip.

## Capabilities

### New Capabilities

- `vault-custom-fields`
- `vault-ssh-key-item`

### Modified Capabilities

- None in delta form.

## Impact

- **Frontend**: `AdditionalFieldsEditor.vue`, `SecretDetailSidebar.vue`, `SecretCreateDialog.vue`, `SecretEditDialog.vue`, a new SSH key section component, `src/cxf/cxf.js`.
- **Backend**: none. The blob is opaque to the server; no migration, no route.
- **Security**: the private key is generated and encrypted in the browser. A hidden field is a display control only; the value is as encrypted as before.
- **l10n**: new strings in every locale the app ships.
