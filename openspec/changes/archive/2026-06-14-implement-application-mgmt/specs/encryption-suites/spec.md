# Encryption Suites — Delta Spec

**Change:** implement-application-mgmt

**Base spec:** `openspec/specs/encryption-suites/spec.md`

## MODIFIED Requirements

### Requirement: Application-Owned EncryptionSuites

EncryptionSuite creation MUST support `owner_type=application`. An application suite differs from a user suite: its `private_key` field MUST be `null` (the application holds its key externally), it is created at application registration/approval (not at master-password setup), its certificate subject MUST be `CN=app:{application_id}`, and it requires no master password.

#### Scenario: Create an application-owned suite
- GIVEN an application is being registered or approved
- WHEN an EncryptionSuite is created with `owner_type=application`
- THEN the suite's `private_key` field MUST be `null`
- AND the certificate subject MUST be `CN=app:{application_id}`
- AND no master password MUST be required

### Requirement: CSR-Based Suite Creation

The service MUST expose a creation path `EncryptionSuiteService::createFromCsr(ownerType, ownerId, csrPem)` that validates the PKCS#10 CSR format, extracts the public key, validates the key size is at least 4096 bits, signs the public key with the CA intermediate via `CertificateAuthorityService`, and creates an EncryptionSuite with `private_key = null`. This path is used exclusively for application registration with a CSR.

#### Scenario: Create suite from a valid CSR
- GIVEN a valid PKCS#10 CSR with a >= 4096-bit public key
- WHEN `createFromCsr` is invoked
- THEN the public key MUST be signed by the CA intermediate
- AND an EncryptionSuite MUST be created with `private_key = null`

#### Scenario: Reject undersized CSR key
- GIVEN a CSR carrying a key smaller than 4096 bits
- WHEN `createFromCsr` is invoked
- THEN the server MUST reject the request

### Requirement: Cascade Deletion of Application Suites

When an application is deleted, its EncryptionSuite MUST be hard-deleted as part of the cascade, bypassing the normal revocation flow (no revoked status, no audit trail).

#### Scenario: Delete application cascades to its suite
- GIVEN an active application with an EncryptionSuite
- WHEN the application is deleted
- THEN the associated EncryptionSuite MUST be hard-deleted in the same transaction
