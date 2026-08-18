# Secret Requests Specification

**Status**: in-progress

**OpenSpec changes:**
- `implement-secret-requests` (2026-03-31) — Full implementation: fill-in links, write-without-read, re-requests, expiry, revocation, notifications
- `request-first-secret-requests` (2026-08-18) — Brings the human flow into line with a MUST it was violating: a fresh request creates its OWN unfilled Secret instead of requiring a pre-existing one with an invented key; re-request stays the only path targeting an existing Secret; a fresh request no longer pre-selects fields that already hold values, decided client-side at creation and never disclosed to the fill recipient

## Purpose

@e2e exclude No secret-request UI is built in v0.1; all scenarios exercise fill-in-link crypto flows and write-without-read API semantics — covered by integration tests, not Playwright UI flows.

A user or application can request that a secret be filled in by an external party. Doriath creates an unfilled Secret (a placeholder with no key value) and generates a fill-in link. Anyone with the link can submit the secret values; the data is encrypted immediately on receipt using the requester's public certificate.

Critically, the requester cannot read the submitted values after they have been stored. This is by design: it prevents the requester from becoming a point of leakage (e.g., an administrator requesting application secrets from a vendor — the vendor fills them in, and the administrator cannot see them).

## Data Model

### SecretRequest

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `secret_id` | FK | No | The unfilled Secret this request writes to |
| `encryption_suite_id` | FK | No | The EncryptionSuite whose public certificate is used to encrypt submitted values. Updated to the new suite during compromise recovery migration. |
| `token` | string | No | URL-safe random token with at least 128 bits of entropy (generated via `random_bytes()`) |
| `requested_fields` | JSON | No | Array of field names the requester is asking for |
| `status` | enum | No | `pending`, `locked`, `fulfilled` — `locked` is set during the requester's compromise recovery migration; fill-in link returns "temporarily unavailable" while locked |
| `expires_at` | datetime | No | Optional expiry set by the requester at creation time; null = no expiry |
| `created_at` | datetime | No | |
| `fulfilled_at` | datetime | No | |

The Secret linked to a SecretRequest starts with all sensitive fields empty. When the fill-in link is used, the submitted values are encrypted with the requester's public certificate and stored in the Secret.

## Requirements

### Requirement: Create Secret Request
The system MUST allow an authenticated user or application to create a SecretRequest, subject to the following ownership rules:

- A user MAY create a SecretRequest for their own secrets
- A user MAY create a SecretRequest for an application's secrets
- A user MUST NOT create a SecretRequest targeting another user's secret

#### Scenario: Create request for own secret
- GIVEN a user has an active EncryptionSuite
- WHEN they create a SecretRequest specifying which fields to request
- THEN the system MUST create an unfilled Secret and a SecretRequest with a unique token
- AND return the fill-in link to the requester

#### Scenario: Create request for application secret
- GIVEN a user has an active EncryptionSuite and an application exists with an active EncryptionSuite
- WHEN the user creates a SecretRequest targeting the application's vault
- THEN the system MUST create an unfilled Secret owned by the application and a SecretRequest
- AND the submitted values MUST be encrypted with the application's public certificate

#### Scenario: Attempt to create request for another user's secret
- GIVEN user A attempts to create a SecretRequest targeting user B's secret
- WHEN the request is submitted
- THEN the system MUST return an authorization error

### Requirement: Requestable Fields

A `SecretRequest` MUST be able to request every field a Secret supports, and for the encrypted extras it MUST be able to name which ones it wants.

`requested_fields` entries are field names. Three are reserved and map to the Secret's own columns; every other name is an **additional field**, carried inside the single encrypted `additional_fields` blob:

| Requested name | Stored in | Nature |
|---|---|---|
| `key` | `key` | RSA ciphertext |
| `login` | `login` | RSA ciphertext |
| `url` | `url` | **plaintext metadata** (searchable, per the secrets spec) |
| any other name | a member of the `additional_fields` JSON object | RSA ciphertext (whole blob) |

Because `url` is plaintext by design and the rest is ciphertext, a fill submission MUST carry them separately: encrypted values under `encryptedFields` and plaintext metadata under `plainFields`. A client MUST NOT encrypt a plaintext metadata field — storing ciphertext in a searchable column would break search and render the value unusable — and the system MUST refuse a field submitted in the wrong one.

`additional_fields` remains ONE encrypted blob. The requested member names live on the request, which is plaintext non-sensitive metadata; the member names are NOT stored on the Secret. This deliberately keeps the encryption boundary where the secrets, secret-import and cxf-import-export specs put it: only `name`, `url`, type and folder path may be plaintext on a Secret.

The consequence MUST be stated rather than implied: the system CANNOT verify that the requested additional members were actually filled, because it never decrypts the blob (ADR-003). It can only confirm that a blob arrived. Per-member completeness is a client-side concern.

#### Scenario: A request names top-level and additional fields together
- **GIVEN** a requester creates a request for `key`, `url` and `api-interface-id`
- **WHEN** the fill-in link is opened
- **THEN** the recipient MUST be prompted for all three
- **AND** `key` MUST be submitted as ciphertext, `url` as plaintext metadata, and `api-interface-id` as a member of the encrypted `additional_fields` blob

#### Scenario: A plaintext metadata field is submitted as ciphertext
- **GIVEN** a request asking for `url`
- **WHEN** the submission carries `url` under `encryptedFields` instead of `plainFields`
- **THEN** the system MUST refuse it rather than storing ciphertext in a searchable column

#### Scenario: Additional members cannot be verified server-side
- **GIVEN** a request asking for the additional members `api-key` and `api-interface-id`
- **WHEN** an encrypted `additional_fields` blob is submitted
- **THEN** the system MUST accept the blob as satisfying both
- **AND** MUST NOT claim to have verified their presence, which would require decrypting it

### Requirement: Fill In via Link
Anyone with the fill-in link MUST be able to submit values for the requested fields without authentication.

#### Scenario: Fill in request
- GIVEN a SecretRequest with status `pending`
- WHEN an external party submits values via the token link
- THEN the system MUST encrypt the submitted values with the requester's public certificate
- AND store them in the linked Secret
- AND set the SecretRequest status to `fulfilled`

#### Scenario: Requested field names are limited to the Secret's value fields

- GIVEN `requested_fields` is free-form JSON and can therefore name anything
- WHEN a submitted field name is not one of `key`, `login` or `additionalFields`
- THEN the system MUST refuse the submission rather than storing it nowhere
- AND the SecretRequest MUST remain `pending`

#### Scenario: A failed write leaves the request fillable

- GIVEN values have been submitted for a pending request
- WHEN storing them on the linked Secret fails
- THEN the SecretRequest MUST remain `pending` so the link can be used again
- AND it MUST NOT be reported as `fulfilled`

#### Scenario: Requester cannot read after submission
- GIVEN a SecretRequest has been fulfilled
- WHEN the requester retrieves the linked Secret
- THEN the system MUST return the secret in its normal encrypted form — the requester must provide their master password to decrypt it
- AND the fill-in link is no longer usable

### Requirement: Optional Expiry
The requester MAY set an `expires_at` when creating a SecretRequest. If set and the expiry has passed, the fill-in link MUST return an error and no submission is accepted. If not set, the request remains open until fulfilled or manually revoked.

#### Scenario: Expired request accessed
- GIVEN a SecretRequest with an `expires_at` in the past
- WHEN the fill-in link is accessed
- THEN the system MUST return an error and MUST NOT accept a submission

### Requirement: Notification on Fulfillment
When a SecretRequest is fulfilled, the requester MUST receive a Nextcloud notification.

Notification content:
- Body: "Your secret request has been fulfilled"
- Action link: opens the linked Secret in the requester's vault

#### Scenario: Request fulfilled
- GIVEN a SecretRequest with status `pending`
- WHEN an external party successfully submits values
- THEN the requester MUST receive a Nextcloud notification

### Requirement: Field Validation
All fields listed in `requested_fields` MUST be submitted with a non-empty value. The system MUST reject submissions where any requested field is empty or missing.

#### Scenario: Empty field submitted
- GIVEN a SecretRequest requesting fields `[username, password]`
- WHEN the submitter sends values with `password` empty
- THEN the system MUST return a validation error and MUST NOT store any submitted values

### Requirement: Write-Once
Once a SecretRequest is fulfilled, the fill-in link MUST be invalidated and no further submissions accepted. Write-once applies per SecretRequest — a re-request (see below) creates a new SecretRequest with its own lifecycle.

#### Scenario: No second submission after fulfilment
@e2e exclude Server-side write-once contract — the fill-in token is invalidated on fulfilment; covered by PHPUnit, not browser-observable.
- GIVEN a SecretRequest that has already been fulfilled
- WHEN a further submission is attempted on the same fill-in link
- THEN the system MUST reject it and accept no further values for that SecretRequest

### Requirement: Re-request (Update in Place)
The requester MUST be able to create a new SecretRequest against an already-filled Secret (a re-request). This is the mechanism for credential rotation: the external party is asked to submit new values, which overwrite the existing ones in place.

While the re-request is pending, the Secret's existing values remain readable — there is no gap in access. On fulfilment, the new values overwrite the old ones and sync-on-update propagates to all shares automatically.

The same ownership rules apply: a user may re-request for their own secrets or application secrets, but not for another user's secret.

#### Scenario: Re-request for credential rotation
- GIVEN a Secret owned by the requester or an application with a previously fulfilled SecretRequest
- WHEN the requester creates a new SecretRequest against the same Secret
- THEN a new SecretRequest MUST be created with a new token (no new Secret is created)
- AND the existing Secret values MUST remain readable until the new request is fulfilled

#### Scenario: Re-request fulfilled
- GIVEN a re-request is pending and the external party submits new values
- WHEN the submission is accepted
- THEN the new values MUST overwrite the existing Secret fields in place
- AND sync-on-update MUST propagate the new values to all shares
- AND `possibly_compromised_at` MUST be unset if it was set

### Requirement: Revoke Request
The requester MUST be able to revoke a pending SecretRequest before it is fulfilled.

#### Scenario: Revoke pending request (new secret)
- GIVEN a SecretRequest with status `pending` that created a new unfilled Secret
- WHEN the requester revokes it
- THEN the token MUST be invalidated and the unfilled Secret MUST be deleted

#### Scenario: Revoke pending re-request
- GIVEN a re-request with status `pending` (existing Secret with current values)
- WHEN the requester revokes it
- THEN the token MUST be invalidated
- AND the existing Secret and its current values MUST be preserved

## User Stories

- As a user, I want to request a password from a vendor via a link so that they can submit it securely without me ever seeing it in plain text
- As an administrator, I want to request application secrets from a third party so that I can configure the application without the secrets passing through my hands
- As an external party, I want to submit a secret via a link so that I know it is stored securely immediately
- As a user, I want to revoke a request if I sent the link to the wrong person
- As a user, I want to re-request a secret so that a vendor can submit updated credentials without me ever seeing the new values
- As an administrator, I want to create or re-request secrets for applications so that application credentials can be rotated securely

## Acceptance Criteria

- [ ] A SecretRequest creates an unfilled Secret and a unique fill-in token
- [ ] Any authenticated user can create a SecretRequest for their own secrets or for application secrets
- [ ] Creating a SecretRequest targeting another user's secret is rejected with an authorization error
- [ ] The fill-in link can be used without authentication
- [ ] Submitted values are encrypted with the requester's public certificate before storage
- [ ] After submission, the link is invalidated and the request is marked fulfilled
- [ ] The requester cannot read submitted values without their master password (normal decryption flow)
- [ ] A pending request can be revoked by the requester
- [ ] Revoking a request deletes the unfilled Secret and invalidates the token
- [ ] A fulfilled or revoked token returns an error if accessed again
- [ ] Requester may optionally set an expiry on the request; expired requests reject submissions
- [ ] Requester receives a Nextcloud notification when the request is fulfilled
- [ ] Submissions with any empty requested field are rejected — all fields must be non-empty
- [ ] Standard Nextcloud rate limiting applies to the fill-in endpoint; no additional per-token rate limiting required (token entropy makes guessing infeasible; write-once semantics prevent replay)
- [ ] Each new SecretRequest creates its own unfilled Secret; a re-request targets an existing Secret (no new Secret created)
- [ ] While a re-request is pending, the existing Secret values remain readable
- [ ] On re-request fulfilment, new values overwrite the existing Secret fields in place
- [ ] Sync-on-update propagates re-request fulfilment to all shares; `possibly_compromised_at` is unset if set
- [ ] Revoking a re-request preserves the existing Secret and its current values

## Notes

- The write-without-read property is fundamental here — it is a direct consequence of asymmetric encryption. Any implementation that allows the requester to read submitted values without their master password is a security bug.
- Related ADRs: ADR-003 (encryption architecture — write-without-read property)
