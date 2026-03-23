# Secrets Specification

**Status**: planned

**OpenSpec changes:** _(none yet)_

## Purpose

Secrets are the core data entity in Doriath. A secret holds sensitive information (passwords, API keys, tokens, etc.) for a user or application. All sensitive fields are encrypted at rest using the owner's EncryptionSuite public certificate. Only the secret's name is stored in plain text to allow listing without decryption.

## Data Model

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `name` | string | No | Human-readable label — safe to display in lists |
| `key` | text | Yes | The actual secret value (password, API key, token, etc.) |
| `login` | string | Yes | Optional username, client ID, or equivalent |
| `additional_fields` | text | Yes | JSON blob of extra key-value pairs |
| `encryption_suite_id` | FK | No | Which EncryptionSuite was used to encrypt this secret |
| `owner_type` | enum | No | `user` or `application` |
| `owner_id` | string | No | Nextcloud user ID or Application ID |
| `created_at` | datetime | No | |
| `updated_at` | datetime | No | |

## Requirements

### Requirement: Create Secret
The system MUST allow an authenticated user to create a secret with at minimum a name and a key value.

#### Scenario: Create with required fields
- GIVEN a user has an active EncryptionSuite and their master password is in session
- WHEN they submit a new secret with a name and key value
- THEN the system MUST encrypt the key with their public certificate and store it

#### Scenario: Create with all fields
- GIVEN a user has an active EncryptionSuite and their master password is in session
- WHEN they submit a secret with name, key, login, and additional fields
- THEN all fields except name MUST be stored encrypted

### Requirement: Read Secret
The system MUST decrypt and return secret fields when the user provides their master password.

#### Scenario: Read own secret
- GIVEN a user has the master password in session
- WHEN they request a secret they own
- THEN the system MUST return all decrypted fields

#### Scenario: List secrets without master password
- GIVEN a user does NOT have their master password in session
- WHEN they list their secrets
- THEN the system MUST return secret names only (no decrypted values)

### Requirement: Update Secret
The system MUST allow a user to update any field of a secret they own. Updated encrypted fields MUST be re-encrypted before storage.

### Requirement: Delete Secret
The system MUST allow a user to delete a secret they own. Deletion MUST cascade to any SecretShares derived from this secret and any SecretRequests linked to it.

### Requirement: Encryption Suite Link
Each secret MUST record which EncryptionSuite was used to encrypt it, so that the correct private key can be identified for decryption (relevant when multiple encryption suites exist).

## User Stories

- As a user, I want to store a password with a name so that I can retrieve it later without remembering it
- As a user, I want to store a username alongside a password so that I have the full credential in one place
- As a user, I want to add additional fields to a secret so that I can store any relevant metadata (e.g., URL, notes)
- As a user, I want to list my secrets by name so that I can find what I need without entering my master password
- As a user, I want to delete a secret I no longer need

## Acceptance Criteria

- [ ] Secrets can be created with name + key (minimum)
- [ ] Login and additional fields are optional
- [ ] Name is stored and returned in plain text
- [ ] Key, login, and additional fields are stored encrypted and returned decrypted when master password is in session
- [ ] Listing secrets returns names without requiring master password
- [ ] Deleting a secret cascades to all derived shares and requests
- [ ] Each secret records the `encryption_suite_id` used for encryption
- [ ] Secrets are isolated per owner — a user cannot read another user's secrets directly

## Notes

- Additional fields are encrypted as a JSON blob. Chunking must be implemented before large additional values are supported (see ADR-003 on RSA chunk limits).
- The key generator feature integrates with secret creation to auto-generate the key value.
- Related ADRs: ADR-001 (own DB tables), ADR-003 (encryption architecture)
