# Design: show which algorithms and key sizes protect the vault

## Context

At development `4c214a9d`:

- `src/crypto/rsa.js:2-8` RSA-OAEP-SHA256, `RSA_KEY_BITS = 4096`, chunking above 446 bytes; `:20-21` key generation with `modulusLength`; `:64`, `:187` import with SHA-256.
- `src/crypto/aes.js:2` AES-256-GCM with PBKDF2-SHA256; `:15` `PBKDF2_ITERATIONS = 600000`; `:55` `encryptPrivateKey()`, `:79` `decryptPrivateKey()`.
- `src/crypto/argon2.js:19-34` `ARGON2_MEMORY_KIB = 65536`, `ARGON2_ITERATIONS = 3`, `ARGON2_PARALLELISM = 1`, `SALT_LENGTH = 16`; used by the encrypted backup (`src/export/backup.js`), link shares and ephemeral sends.
- Attachments: AES-GCM file keys wrapped with RSA (`src/store/modules/attachment.js:115`).
- `src/App.vue:166-186` Encryption section: status, created, suite id.
- `lib/Service/CertificateLifecycleService.php:180-187` parsed certificate metadata: subject, issuer, serial, SHA-256 fingerprint, notBefore, notAfter; `lib/Controller/CertificateController.php:95` `inventory()`.
- `src/views/CertificateInventoryView.vue:116-137` vault certificate table: owner, subject, expires.
- `docs/ARCHITECTURE.md:65-117` describes the model in prose.

## Goals / Non-Goals

**Goals**
- Anyone with a vault can see what protects it, in words they understand and names an auditor recognises.
- The screen cannot say something the code does not do.

**Non-Goals**
- Changing any algorithm or parameter.
- A per-secret view. Every secret of a suite uses the same scheme.

## Decisions

**D1. Read the parameters from the code, not from copy.** Each crypto module exports its parameters (`RSA_PARAMETERS`, `MASTER_KEY_PARAMETERS`, `ARGON2_PARAMETERS`) and `src/crypto/parameters.js` assembles `CRYPTO_PARAMETERS`. The component renders from that object. A vitest asserts the exported values are the ones the functions use, so changing a constant changes the screen.

**D2. Read the certificate facts from the certificate.** The server already parses the vault certificate for the inventory. It adds `keyType` and `keyBits` from `openssl_pkey_get_details()` on the certificate's public key and `signatureAlgorithm` from the parsed certificate. The browser does not re-derive them.

**D3. Plain words first, technical names second.** Each line reads like "Your passwords are encrypted with your own 4096-bit RSA key (RSA-OAEP, SHA-256)", following the hydra writing rules, with the technical name for auditors in the same line.

**D4. The docs page is generated.** The docs build imports `src/crypto/parameters.js` and renders the same table, so the public page and the app agree.

## Security and zero-knowledge

Everything shown is public: algorithm names, parameter values, and the certificate's public fields. No private key, envelope, salt or secret is read or displayed. Publishing parameters does not weaken them.

## Risks / Trade-offs

- A reader may compare numbers across vendors without context (4096-bit RSA against 2048-bit, 600,000 against 1,000,000 iterations). The docs page explains what each number protects.

## Seed data

Keepiq owns its tables (keepiq ADR-001) and has no OpenRegister register. No fixture is needed; every dev vault has a suite.

## Migration

None.
