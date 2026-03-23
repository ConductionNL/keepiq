# Encryption Suites Specification

**Status**: planned

**OpenSpec changes:** _(none yet)_

## Purpose

An EncryptionSuite is the cryptographic identity of a user or application within Doriath. It holds a public certificate (used to encrypt secrets for the owner) and an AES-encrypted private key (used to decrypt them). EncryptionSuites are signed by the application's internal Certificate Authority.

Every user who opens Doriath gets an EncryptionSuite. Every registered Application gets one when a CSR is submitted or a key pair is generated on their behalf.

## Data Model

### EncryptionSuite

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `owner_type` | enum | No | `user` or `application` (see ADR-002) |
| `owner_id` | string | No | Nextcloud user ID or Application ID |
| `certificate` | text | No | PEM public certificate (signed by CA intermediate) |
| `private_key` | text | Yes (AES) | PEM private key, AES-256 encrypted with master password |
| `status` | enum | No | `active`, `revoked` |
| `revoked_at` | datetime | No | Null if active |
| `revoked_reason` | string | No | Null if active |
| `created_at` | datetime | No | |

### CACertificate

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `type` | enum | No | `root` or `intermediate` |
| `certificate` | text | No | PEM certificate |
| `private_key` | text | Yes (AES) | PEM private key — only present for intermediate; AES-encrypted |
| `created_at` | datetime | No | |
| `is_active` | bool | No | Only one intermediate is active for signing at a time |

## Requirements

### Requirement: Suite Creation on First Login
The system MUST automatically create an EncryptionSuite for a Nextcloud user the first time they open Doriath and provide a master password.

#### Scenario: First-time user setup
- GIVEN a Nextcloud user has no existing EncryptionSuite
- WHEN they open Doriath and provide a master password
- THEN the system MUST generate a 4096-bit RSA key pair, sign the public key with the active CA intermediate, and store the encrypted private key

### Requirement: Master Password in Session
The system MUST store the master password in the user session after successful entry. The session timeout MUST be configurable (session duration, 10 minutes, or 30 minutes).

#### Scenario: Session expiry
- GIVEN a user's session timeout has elapsed
- WHEN they attempt to decrypt a secret
- THEN the system MUST prompt them to re-enter their master password

### Requirement: Revocation
The system MUST allow a user or administrator to revoke an EncryptionSuite.

#### Scenario: Revoke suite
- GIVEN an EncryptionSuite is active
- WHEN it is revoked (by user or admin)
- THEN its status MUST be set to `revoked` with reason and timestamp
- AND it MUST NOT be used for new encryption operations
- AND all secrets encrypted with it MUST be flagged as inaccessible until re-encrypted

### Requirement: Minimum Key Size
The system MUST generate RSA keys of at least 4096 bits. The minimum MUST only be allowed to increase, never decrease.

### Requirement: CA Bootstrap
The system MUST generate a private CA (root + intermediate) on first setup if no CA has been configured.

#### Scenario: CA bootstrap
- GIVEN Doriath has no CA certificates
- WHEN the repair/install step runs
- THEN the system MUST generate a root certificate and a signing intermediate certificate
- AND store the intermediate's private key AES-encrypted in the database

## User Stories

- As a new user, I want Doriath to set up my encryption automatically when I first enter my master password
- As a user, I want to choose how long my master password stays in my session so that I balance security with convenience
- As a user, I want to revoke my encryption suite if I suspect my private key has been compromised
- As an administrator, I want to revoke a user's encryption suite if their credentials are compromised

## Acceptance Criteria

- [ ] An EncryptionSuite is created automatically for a user on first login to Doriath
- [ ] RSA key size is at least 4096 bits
- [ ] Private key is stored AES-256 encrypted — never in plaintext
- [ ] Master password is never stored in the database
- [ ] Session timeout is configurable (session / 10 min / 30 min)
- [ ] Suites can be revoked by user or admin with a reason
- [ ] Revoked suites cannot be used for new encryption
- [ ] A CA (root + intermediate) is bootstrapped on first setup if none exists
- [ ] All certificates are signed by the active intermediate

## Notes

- Multiple encryption suites per owner (key rotation, compromise handling) are scoped to a future change, not this spec.
- CA upload (custom CA chain) is also scoped as advanced functionality.
- Related ADRs: ADR-002 (polymorphic ownership), ADR-003 (encryption architecture)
