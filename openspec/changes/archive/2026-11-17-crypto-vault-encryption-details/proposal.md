---
kind: code
---

# Show which algorithms and key sizes protect the vault

## Why

Keepiq's encryption is stated in code constants and in the architecture document, not on any screen. The secret values are RSA-OAEP with SHA-256 under 4096-bit keys (`src/crypto/rsa.js:8`, `:20-21`, `:64`), the private key is wrapped with AES-256-GCM under a key derived from the master password with PBKDF2-SHA256 at 600,000 iterations (`src/crypto/aes.js:2`, `:15`), and backups and link shares use Argon2id with 64 MiB, 3 iterations and parallelism 1 (`src/crypto/argon2.js:19-25`). The user settings Encryption section shows only the suite's status, creation date and id (`src/App.vue:166-186`), and the certificates page shows the vault certificate's owner, subject and expiry (`src/views/CertificateInventoryView.vue:116-137`). A security officer who has to fill in a supplier questionnaire, or a user who wants to know what "zero-knowledge" means here, has to read source code.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `crypto-13` | See which algorithms and key sizes protect your vault. | `no`: no screen names the algorithms or key sizes; the certificates page shows subject and expiry only |

### Demand

No demand row. Three competitors rate it yes.

### Competitors rated yes

- 1Password: AES256-GCM, RSA-OAEP 2048, PBKDF2-HMAC-SHA256 (https://1passwordstatic.com/files/security/1password-white-paper.pdf).
- Passbolt: "src/react-extension/components/UserSetting/DisplayUserGpgInformation/DisplayUserGpgInformation.js:111 algorithm type, :272 algorithm cell (plus length, fingerprint, created, expires); passbolt/passbolt_api@v5.16.0 config/routes.php:119 GET /gpgkeys Note: The keys inspector shows key algorithm, length, fingerprint and expiry."
- Keeper: AES-256 record keys, PBKDF2 1,000,000 iterations, ECC secp256r1, RSA-2048 documented (https://docs.keeper.io/enterprise-guide/keeper-encryption-model).

## What Changes

- **An encryption overview in user settings.** The Encryption section lists, in plain words with the technical names next to them: how secret values are encrypted, how the private key is protected by the master password, how attachments are encrypted, how backups, link shares and sends are protected, and the vault certificate's key size, signature algorithm, fingerprint, issuer and validity.
- **One source of truth.** The web app exports the parameters from the crypto modules and the overview reads them from there, so the screen cannot drift from the code. The certificate facts are read from the certificate itself by the server.
- **The certificates page shows key size and algorithm** for the vault certificate next to its subject and expiry.
- **A documentation page** repeats the overview for readers without an account, generated from the same constants in the docs build.

## Capabilities

### New Capabilities

- `vault-encryption-details`: a user-facing account of the algorithms, key sizes and key derivation settings that protect the vault, taken from the code and the certificate.

### Modified Capabilities

- None in delta form. `certificate-lifecycle` keeps its inventory requirement; the added certificate fields are this change's own requirement.

## Impact

- **Backend**: `CertificateLifecycleService` adds `keyType`, `keyBits` and `signatureAlgorithm` to the parsed certificate metadata of the inventory.
- **Frontend**: a `CRYPTO_PARAMETERS` export assembled from `src/crypto/rsa.js`, `aes.js` and `argon2.js`; a `VaultEncryptionDetails.vue` in the Encryption section of `App.vue`; two columns on `CertificateInventoryView.vue`.
- **Docs**: a generated section in `docs/ARCHITECTURE.md` or a new page under `docs/`.
- **Database**: none.
- **Security**: only public parameters and public certificate fields are shown; no key material.
- **Cross-app**: none.
