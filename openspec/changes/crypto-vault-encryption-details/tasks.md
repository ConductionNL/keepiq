# Tasks: show which algorithms and key sizes protect the vault

## 1. Parameters

- [ ] 1.1 Export the parameter objects from `rsa.js`, `aes.js` and `argon2.js` and assemble `src/crypto/parameters.js`. Verify: vitest that each exported value equals the value the module's functions use.
- [ ] 1.2 Add `keyType`, `keyBits` and `signatureAlgorithm` to the parsed certificate metadata in `CertificateLifecycleService`. Verify: PHPUnit on a generated 4096-bit test certificate.

## 2. Screens

- [ ] 2.1 Add `VaultEncryptionDetails.vue` to the Encryption section of the user settings, rendered from `CRYPTO_PARAMETERS` and the user's suite certificate. Verify: vitest on the rendered lines, and a Playwright check that the section names RSA-OAEP, 4096, AES-256-GCM, PBKDF2 and 600,000.
- [ ] 2.2 Add key size and signature algorithm columns to the vault certificate table in `CertificateInventoryView.vue`. Verify: Playwright check on /certificates.

## 3. Docs

- [ ] 3.1 Generate the encryption overview page in the docs build from `src/crypto/parameters.js`. Verify: docs build and a diff check that the page lists the same values as the app.

## Acceptance criteria

- The Encryption section of the user settings states how values, the private key, attachments and backups are protected, with algorithm names and parameters.
- The vault certificate's key type, key size and signature algorithm are shown on /certificates.
- Changing a parameter in the crypto code changes the screen and the docs page without other edits.
