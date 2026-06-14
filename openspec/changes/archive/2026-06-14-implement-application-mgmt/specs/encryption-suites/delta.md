# Encryption Suites — Delta Spec

**Change:** implement-application-mgmt

**Base spec:** `openspec/specs/encryption-suites/spec.md`

## Modified Behavior

### Application-Owned EncryptionSuites

EncryptionSuite creation now supports `owner_type=application` with the following differences from user-owned suites:

| Aspect | User Suite | Application Suite |
|--------|-----------|-------------------|
| `private_key` field | AES-256-GCM encrypted blob | `null` (app holds externally) |
| Creation trigger | User's first master password setup | Application registration/approval |
| Certificate subject | `CN=user:{nextcloud_uid}` | `CN=app:{application_id}` |
| Master password | Required for private key decryption | N/A (app manages its own key) |

### CSR-Based Suite Creation

A new creation path via `EncryptionSuiteService::createFromCsr(ownerType, ownerId, csrPem)`:
1. Validate PKCS#10 CSR format
2. Extract public key from CSR
3. Validate key size >= 4096 bits
4. Sign public key with CA intermediate via CertificateAuthorityService
5. Create EncryptionSuite with `private_key = null`

This path is used exclusively for application registration with a CSR.

### Cascade Deletion

When an application is deleted, its EncryptionSuite is hard-deleted as part of the cascade. This bypasses the normal revocation flow (no revoked status, no audit trail). The suite is simply removed.
