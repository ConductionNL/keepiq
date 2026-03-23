# User Sharing Specification

**Status**: planned

**OpenSpec changes:** _(none yet)_

## Purpose

A user can share a secret with another Nextcloud user. Because encryption is asymmetric, sharing works by creating an encrypted copy of the secret using the recipient's public certificate. Both the original and all shared copies stay in sync: when either party updates the secret, the change is written back to all copies.

## Data Model

### SecretShare (user-to-user)

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `source_secret_id` | FK | No | The original secret being shared |
| `target_user_id` | string | No | Nextcloud user ID of the recipient |
| `secret_id` | FK | No | The encrypted copy in the recipient's vault |
| `created_at` | datetime | No | |

The recipient's copy is itself a full `Secret` row, encrypted with the recipient's EncryptionSuite. The `SecretShare` links the two.

## Requirements

### Requirement: Share a Secret
The system MUST allow a user to share a secret they own with another Nextcloud user who has an active EncryptionSuite.

#### Scenario: Share with valid recipient
- GIVEN user A owns a secret and user B has an active EncryptionSuite
- WHEN user A shares the secret with user B
- THEN the system MUST create an encrypted copy of the secret in user B's vault using user B's public certificate
- AND create a SecretShare linking the original to the copy

#### Scenario: Share with user without EncryptionSuite
- GIVEN user B has never opened Doriath and has no EncryptionSuite
- WHEN user A attempts to share a secret with user B
- THEN the system MUST return an error indicating the recipient has no encryption suite

### Requirement: Sync on Update
When either party updates a shared secret, the change MUST be propagated to all copies.

#### Scenario: Owner updates shared secret
- GIVEN a secret is shared with one or more users
- WHEN the owner updates the secret's value
- THEN the system MUST re-encrypt the updated value for each recipient and write it to their copy

#### Scenario: Recipient updates shared secret
- GIVEN a secret has been shared with user B
- WHEN user B updates their copy of the secret
- THEN the system MUST re-encrypt the updated value for the original owner and all other recipients

### Requirement: Revoke Share
The system MUST allow the original owner to revoke a share, removing the recipient's copy.

#### Scenario: Revoke share
- GIVEN a share exists between user A and user B
- WHEN user A revokes the share
- THEN the recipient's Secret copy MUST be deleted
- AND the SecretShare record MUST be removed

## User Stories

- As a user, I want to share a password with a colleague so that we both have access to a shared account
- As a user, I want changes I make to a shared secret to be visible to my colleagues immediately
- As a user, I want to revoke a share when a colleague leaves the team

## Acceptance Criteria

- [ ] A secret can be shared with any Nextcloud user who has an active EncryptionSuite
- [ ] The recipient receives an encrypted copy in their own vault
- [ ] The copy is encrypted with the recipient's public certificate — the sender cannot read it after sharing
- [ ] Updates by either party propagate to all copies of the shared secret
- [ ] The original owner can revoke a share at any time
- [ ] Revoking a share deletes the recipient's copy
- [ ] Sharing fails with a clear error if the recipient has no EncryptionSuite

## Notes

- The sync-on-update requirement means that updating a widely-shared secret triggers multiple re-encryption operations. For the initial implementation, this can be synchronous. Async fanout can be explored if performance becomes an issue.
- Related ADRs: ADR-003 (encryption architecture — write-without-read property)
