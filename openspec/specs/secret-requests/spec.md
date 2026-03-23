# Secret Requests Specification

**Status**: planned

**OpenSpec changes:** _(none yet)_

## Purpose

A user or application can request that a secret be filled in by an external party. Doriath creates an unfilled Secret (a placeholder with no key value) and generates a fill-in link. Anyone with the link can submit the secret values; the data is encrypted immediately on receipt using the requester's public certificate.

Critically, the requester cannot read the submitted values after they have been stored. This is by design: it prevents the requester from becoming a point of leakage (e.g., an administrator requesting application secrets from a vendor — the vendor fills them in, and the administrator cannot see them).

## Data Model

### SecretRequest

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `secret_id` | FK | No | The unfilled Secret this request writes to |
| `token` | string | No | URL-safe token for the fill-in link |
| `requested_fields` | JSON | No | Array of field names the requester is asking for |
| `status` | enum | No | `pending`, `fulfilled` |
| `created_at` | datetime | No | |
| `fulfilled_at` | datetime | No | |

The Secret linked to a SecretRequest starts with all sensitive fields empty. When the fill-in link is used, the submitted values are encrypted with the requester's public certificate and stored in the Secret.

## Requirements

### Requirement: Create Secret Request
The system MUST allow an authenticated user or application to create a SecretRequest for a target Secret.

#### Scenario: Create request
- GIVEN a user has an active EncryptionSuite
- WHEN they create a SecretRequest specifying which fields to request
- THEN the system MUST create an unfilled Secret and a SecretRequest with a unique token
- AND return the fill-in link to the requester

### Requirement: Fill In via Link
Anyone with the fill-in link MUST be able to submit values for the requested fields without authentication.

#### Scenario: Fill in request
- GIVEN a SecretRequest with status `pending`
- WHEN an external party submits values via the token link
- THEN the system MUST encrypt the submitted values with the requester's public certificate
- AND store them in the linked Secret
- AND set the SecretRequest status to `fulfilled`

#### Scenario: Requester cannot read after submission
- GIVEN a SecretRequest has been fulfilled
- WHEN the requester retrieves the linked Secret
- THEN the system MUST return the secret in its normal encrypted form — the requester must provide their master password to decrypt it
- AND the fill-in link is no longer usable

### Requirement: Write-Once
Once a SecretRequest is fulfilled, the fill-in link MUST be invalidated and no further submissions accepted.

### Requirement: Revoke Request
The requester MUST be able to revoke a pending SecretRequest before it is fulfilled.

#### Scenario: Revoke pending request
- GIVEN a SecretRequest with status `pending`
- WHEN the requester revokes it
- THEN the token MUST be invalidated and the unfilled Secret MUST be deleted

## User Stories

- As a user, I want to request a password from a vendor via a link so that they can submit it securely without me ever seeing it in plain text
- As an administrator, I want to request application secrets from a third party so that I can configure the application without the secrets passing through my hands
- As an external party, I want to submit a secret via a link so that I know it is stored securely immediately
- As a user, I want to revoke a request if I sent the link to the wrong person

## Acceptance Criteria

- [ ] A SecretRequest creates an unfilled Secret and a unique fill-in token
- [ ] The fill-in link can be used without authentication
- [ ] Submitted values are encrypted with the requester's public certificate before storage
- [ ] After submission, the link is invalidated and the request is marked fulfilled
- [ ] The requester cannot read submitted values without their master password (normal decryption flow)
- [ ] A pending request can be revoked by the requester
- [ ] Revoking a request deletes the unfilled Secret and invalidates the token
- [ ] A fulfilled or revoked token returns an error if accessed again

## Notes

- The write-without-read property is fundamental here — it is a direct consequence of asymmetric encryption. Any implementation that allows the requester to read submitted values without their master password is a security bug.
- Related ADRs: ADR-003 (encryption architecture — write-without-read property)
