# Link Sharing Specification

**Status**: in-progress

**OpenSpec changes:**
- `implement-link-sharing` (2026-03-31) — Full implementation: link creation, Argon2id snapshot encryption, brute-force protection, access page, usage limits

## Purpose

A user can share a secret with an external party via a one-time or limited-use link. The link recipient enters a password to decrypt the secret. The sharing user sets how many times the link can be used; when the limit is reached, the share is automatically removed.

This allows secrets to be securely handed off to parties without Nextcloud accounts.

## Data Model

### LinkShare

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `secret_id` | FK | No | The secret being shared |
| `token` | string | No | URL-safe random token with at least 128 bits of entropy (generated via `random_bytes()`), part of the share link |
| `encrypted_secret_snapshot` | text | Yes | Encrypted copy of the secret at share creation time |
| `encryption_suite_id` | FK | No | Suite used to encrypt the snapshot |
| `usage_limit` | int | No | Max number of accesses; minimum 1, maximum 10, default 1; unlimited is not allowed |
| `usage_count` | int | No | Times the link has been accessed and decrypted |
| `created_at` | datetime | No | |
| `expires_at` | datetime | No | Optional expiry (null = no expiry) |

Note: The link password is not stored. The snapshot is re-encrypted with a key derived from the link password using Argon2id. The server cannot decrypt it without the password being supplied.

## Requirements

### Requirement: Create Link Share
The system MUST allow a user to create a link share for a secret they own. The system MUST generate a link token and a one-time password for the recipient.

#### Scenario: Create link share
- GIVEN a user owns a secret and has their master password in session
- WHEN they create a link share with a usage limit of N (1-10, default 1)
- THEN the system MUST generate a unique token, encrypt a snapshot of the secret using a key derived from a generated password, and return both the link and the password to the user
- AND the password MUST NOT be stored or recoverable server-side

### Requirement: Access via Link
The system MUST allow anyone with the link and password to decrypt and retrieve the secret.

#### Scenario: Valid access
- GIVEN a link share exists and has not reached its usage limit
- WHEN the recipient provides the correct token and password
- THEN the system MUST decrypt and return the secret snapshot
- AND increment the usage count by 1

#### Scenario: Usage limit reached
- GIVEN the usage count equals the usage limit
- WHEN the token is accessed
- THEN the system MUST return an error and MUST NOT return any secret data
- AND MUST delete the link share

#### Scenario: Wrong password
- GIVEN a valid token
- WHEN the recipient provides an incorrect password
- THEN decryption fails and the system MUST return an error without exposing any secret data

### Requirement: Auto-deletion
When the usage count reaches the usage limit, the link share and its encrypted snapshot MUST be automatically deleted.

### Requirement: KDF for Snapshot Encryption
The snapshot MUST be encrypted using AES-256 with a key derived from the link password via **Argon2id**. Argon2id is chosen over PBKDF2 for its memory-hardness, which significantly increases the cost of brute-force attacks against a captured snapshot.

### Requirement: Brute-Force Protection
The system MUST rate-limit password attempts by token. After **5 consecutive failed password attempts** on a given token, the link share MUST be permanently deleted (same as reaching the usage limit). This is treated as a hostile access attempt.

#### Scenario: Brute-force attempt
- GIVEN a valid link share token
- WHEN 5 consecutive incorrect passwords are submitted
- THEN the link share MUST be permanently deleted
- AND subsequent requests with the same token MUST return a "not found" error

### Requirement: Snapshot Staleness (by design)
A link share captures a snapshot of the secret at the moment of creation. If the original secret is updated after the link is created, the link continues to serve the snapshot value. This is intentional — the owner must revoke and re-create the link to share an updated value.

### Requirement: Multiple Concurrent Link Shares
A secret MAY have multiple active link shares simultaneously. Each link has its own token, password, usage limit, and lifecycle. The owner can revoke any of them independently.

### Requirement: Manual revocation
The secret owner MUST be able to revoke a link share before the usage limit is reached.

## User Stories

- As a user, I want to share a password with an external party via a link so that they can access it without having a Nextcloud account
- As a user, I want to set how many times the link can be used so that the share self-destructs after the recipient has accessed it
- As a user, I want to revoke a link share if I sent it to the wrong person

## Acceptance Criteria

- [ ] A link share generates a unique URL token and a password
- [ ] The password is shown to the user exactly once and not stored server-side
- [ ] The secret snapshot is encrypted such that only the password can decrypt it
- [ ] The usage limit is configurable (minimum 1, maximum 10, default 1); unlimited is not allowed
- [ ] Each access increments the usage count
- [ ] When the usage limit is reached, the share is automatically deleted
- [ ] The owner can revoke a link share at any time
- [ ] An invalid or expired token returns an error with no secret data
- [ ] Snapshot is encrypted using AES-256 with a key derived from the link password via Argon2id
- [ ] Link token has at least 128 bits of entropy, generated via `random_bytes()`
- [ ] After 5 consecutive failed password attempts, the link share is permanently deleted
- [ ] The link snapshot is a point-in-time copy; updates to the original secret do not affect active link shares
- [ ] A secret may have multiple active link shares simultaneously

## Notes

- The password used to encrypt the snapshot must not be stored. The snapshot AES key is derived from the link password using Argon2id — never stored. This is distinct from RSA encryption used for regular secret storage.
- The document specifies this as a password the user "enters" — in practice, the UX should show the generated password prominently once, with a copy button.
- Related ADRs: ADR-003 (encryption architecture)
