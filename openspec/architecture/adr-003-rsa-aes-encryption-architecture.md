# ADR-003: RSA + AES Encryption Architecture

**Status**: accepted

**Date**: 2026-03-23

## Context

Doriath stores secrets (passwords, API keys, and other sensitive values) for multiple users and applications. The core security requirement is that:

1. Secrets are encrypted at rest and can only be decrypted by the holder of the correct private key
2. A user or application can write a secret into another party's vault without being able to read it afterwards
3. Private keys are never stored in plaintext — they are always protected by a master password
4. The master password is never stored in the database

These requirements rule out symmetric encryption (where the same key encrypts and decrypts, making write-without-read impossible) and any architecture where the server can decrypt secrets without user involvement.

## Decision

Doriath uses a hybrid RSA + AES encryption scheme, implemented via OpenSSL in PHP:

**Secrets are encrypted with RSA (asymmetric):**
- Each user and application has an EncryptionSuite containing a public/private key pair
- Secrets are encrypted with the owner's public certificate — only the private key can decrypt them
- This enables write-without-read: any party can encrypt a secret for an owner without being able to read it back
- Minimum key size: 4096 bits (system default; only allowed to increase)

**Private keys are encrypted with AES (symmetric):**
- The private key in each EncryptionSuite is stored AES-256 encrypted using the owner's master password
- The master password is never stored in the database
- For Nextcloud users: master password is held in the user session (configurable timeout: session, 10 min, or 30 min)
- For external applications: master password is passed via the `X-Vault-Password` HTTP header on each request

**Certificate Authority:**
- A private CA is bootstrapped on first setup: one root certificate and one intermediate certificate
- User and application public certificates are signed by the intermediate
- The intermediate's private key is stored AES-encrypted in the database
- Administrators may upload their own CA chain (root + signing intermediate) instead of using the generated one

**Chunking:**
- RSA encryption has a per-chunk limit of approximately 500 bytes (key size in bytes − 1 − 11 bytes PKCS#1 padding)
- Large values (additional fields, future file encryption) must be chunked before encryption
- Chunking strategy will be defined before implementing support for additional encrypted fields

**Post-quantum note:**
- Post-quantum cryptography algorithms are not yet available in stable PHP/OpenSSL libraries (as of 2026-03-23)
- This architecture will need revisiting when post-quantum support becomes available in PHP

## Consequences

**Positive:**
- Write-without-read is natively supported by asymmetric encryption — no additional mechanism needed
- OpenSSL compatibility means Doriath encryption suites are interoperable with standard PKI tooling
- Master password never at rest — even a full DB dump cannot be decrypted without user involvement
- CA structure enables future Certificate Authority functionality with minimal additional work

**Negative / trade-offs:**
- Chunking complexity for data larger than ~500 bytes — must be implemented carefully and uniformly
- Master password in session is a temporary secret that can be lost (session expiry, server restart)
- If the master password is lost, secrets become permanently inaccessible — no recovery path
- RSA operations are slower than symmetric encryption — acceptable for secrets (small payloads), not for bulk data
- Post-quantum vulnerability is a known future risk

## Alternatives Considered

| Option | Reason not chosen |
|--------|------------------|
| Symmetric AES only | Cannot support write-without-read; server holds decrypt key — breaks security model |
| Client-side encryption (encrypt in browser) | Master password never reaches server, but complicates API access for applications; out of scope for current architecture |
| External KMS (AWS KMS, HashiCorp Vault) | Breaks Nextcloud-native design; adds external dependency and cost |
| Age / libsodium (modern alternatives to OpenSSL RSA) | Not yet universally available in PHP distributions; post-quantum support also not mature |
