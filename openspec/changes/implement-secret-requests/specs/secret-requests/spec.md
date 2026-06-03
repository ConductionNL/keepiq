## ADDED Requirements

### Requirement: Create Secret Request
The system MUST allow an authenticated user to create a SecretRequest, subject to the following ownership rules:

- A user MUST be able to create a SecretRequest for their own secrets
- A user MUST be able to create a SecretRequest for an application's secrets
- A user MUST NOT be able to create a SecretRequest targeting another user's secret
- The user MUST have an active EncryptionSuite (status `active`)
- The system MUST enforce at most one pending SecretRequest per Secret

When creating a new request (no existing secret), the system MUST:
1. Create an unfilled Secret (all encrypted fields empty) owned by the requester (or the application for application requests)
2. Generate a unique token with at least 128 bits of entropy via server-side `random_bytes()`
3. Create a SecretRequest record linking the token to the unfilled Secret
4. Return the token and fill-in link URL to the requester

The `encryption_suite_id` on the SecretRequest MUST reference:
- The user's active EncryptionSuite for user-owned secrets
- The application's active EncryptionSuite for application-owned secrets

#### Scenario: Create request for own secret
- **WHEN** an authenticated user with an active EncryptionSuite creates a SecretRequest specifying requested_fields `["key", "login"]`
- **THEN** the system MUST create an unfilled Secret and a SecretRequest with a unique token
- **THEN** the system MUST return the token and fill-in link to the requester

#### Scenario: Create request for application secret
- **WHEN** a user creates a SecretRequest targeting an application's vault with requested_fields `["key"]`
- **THEN** the system MUST create an unfilled Secret owned by the application
- **THEN** the SecretRequest's encryption_suite_id MUST reference the application's active EncryptionSuite

#### Scenario: Attempt to create request for another user's secret
- **WHEN** user A attempts to create a SecretRequest targeting user B's secret
- **THEN** the system MUST return an authorization error (403)

#### Scenario: Create request without active EncryptionSuite
- **WHEN** a user without an active EncryptionSuite attempts to create a SecretRequest
- **THEN** the system MUST return an error indicating no active suite

#### Scenario: Create request when pending request already exists
- **WHEN** a user creates a SecretRequest for a Secret that already has a pending SecretRequest
- **THEN** the system MUST return an error indicating a pending request already exists

### Requirement: Fill In via Public Link
Anyone with the fill-in link MUST be able to submit values for the requested fields without Nextcloud authentication. The fill-in page MUST encrypt submitted values with the requester's public certificate in the browser before transmission.

The public API endpoint MUST return:
- The list of requested fields
- The requester's public certificate (from the linked EncryptionSuite)
- The request status

The fill-in submission endpoint MUST:
1. Validate the request status is `pending`
2. Validate the request has not expired
3. Validate all requested fields are present and non-empty in the submission
4. Store the encrypted blobs in the linked Secret's fields
5. Set the request status to `fulfilled` and record `fulfilled_at`
6. Send a fulfillment notification to the requester

The fill-in page MUST encrypt each field value using RSA-OAEP-SHA256 with the requester's public certificate. For values exceeding ~446 bytes, the standard RSA chunking from `src/crypto/rsa.js` MUST be used. The encrypted blobs are stored directly in the Secret's encrypted fields (`key`, `login`, `additional_fields`).

#### Scenario: Fill in pending request
- **WHEN** an external party accesses a fill-in link with a valid pending token
- **THEN** the system MUST return the requested fields and the requester's public certificate
- **WHEN** the external party submits encrypted values for all requested fields
- **THEN** the system MUST store the encrypted blobs in the linked Secret
- **THEN** the system MUST set the SecretRequest status to `fulfilled`

#### Scenario: Access fulfilled request
- **WHEN** an external party accesses a fill-in link for an already-fulfilled request
- **THEN** the system MUST return an error indicating the request has already been fulfilled

#### Scenario: Access invalid token
- **WHEN** an external party accesses a fill-in link with a non-existent token
- **THEN** the system MUST return a 404 error

### Requirement: Write-Once Semantics
Once a SecretRequest is fulfilled, the fill-in link MUST be permanently invalidated. No further submissions MUST be accepted for that token. Write-once applies per SecretRequest -- a re-request creates a new SecretRequest with its own lifecycle and token.

#### Scenario: Second submission after fulfillment
- **WHEN** an external party attempts to submit values to an already-fulfilled request
- **THEN** the system MUST reject the submission with an error
- **THEN** the previously stored values MUST remain unchanged

### Requirement: Field Validation
All fields listed in `requested_fields` MUST be submitted with a non-empty encrypted value. The system MUST reject submissions where any requested field is missing or has an empty value. Rejection MUST be atomic -- no partial storage.

#### Scenario: Missing requested field
- **WHEN** a request has requested_fields `["key", "login"]` and the submitter sends only `{"key": "<blob>"}`
- **THEN** the system MUST return a validation error
- **THEN** the Secret MUST NOT be modified

#### Scenario: Empty value for requested field
- **WHEN** a request has requested_fields `["key", "login"]` and the submitter sends `{"key": "<blob>", "login": ""}`
- **THEN** the system MUST return a validation error
- **THEN** the Secret MUST NOT be modified

### Requirement: Optional Expiry
The requester MUST be able to optionally set an `expires_at` datetime when creating a SecretRequest. If set and the expiry has passed, the fill-in link MUST return an error and no submission MUST be accepted. If not set, the request remains open until fulfilled or manually revoked.

#### Scenario: Expired request accessed
- **WHEN** an external party accesses a fill-in link for a request with `expires_at` in the past
- **THEN** the system MUST return an error indicating the request has expired
- **THEN** the system MUST NOT accept a submission

#### Scenario: Request without expiry
- **WHEN** a request is created with `expires_at` set to null
- **THEN** the request MUST remain accessible until fulfilled or revoked

### Requirement: Notification on Fulfillment
When a SecretRequest is fulfilled, the requester MUST receive a Nextcloud notification via NotificationService with subject `request_fulfilled`. The notification MUST include:
- Message: "Your request for {secret_name} has been filled in"
- Action link: deep-link to the secret detail page

The notification MUST respect the user's `notify_requests` preference setting (default: true).

#### Scenario: Request fulfilled notification
- **WHEN** an external party successfully submits values for a pending request
- **THEN** the requester MUST receive a Nextcloud notification with subject `request_fulfilled`
- **THEN** the notification MUST include a link to the fulfilled secret

#### Scenario: Notification preference disabled
- **WHEN** the requester has `notify_requests` set to false
- **THEN** the system MUST NOT send a fulfillment notification

### Requirement: Re-Request for Credential Rotation
The requester MUST be able to create a new SecretRequest against an already-filled Secret (a re-request). This is the mechanism for credential rotation.

A re-request MUST:
- Create a new SecretRequest with a new token pointing to the existing Secret
- NOT create a new Secret
- Preserve the existing Secret's values while the re-request is pending
- On fulfillment: overwrite the existing Secret's encrypted fields in place
- On fulfillment: unset `possibly_compromised_at` if it was set on the Secret

The same ownership rules apply: a user MUST be able to re-request for their own secrets or application secrets, but MUST NOT re-request for another user's secret.

#### Scenario: Create re-request
- **WHEN** a user creates a SecretRequest against an existing Secret that already has values
- **THEN** the system MUST create a new SecretRequest with a new token (no new Secret created)
- **THEN** the existing Secret values MUST remain readable

#### Scenario: Re-request fulfilled
- **WHEN** a re-request is pending and the external party submits new values
- **THEN** the new values MUST overwrite the existing Secret fields in place
- **THEN** `possibly_compromised_at` MUST be unset if it was set
- **THEN** the requester MUST receive a fulfillment notification

#### Scenario: Shared secret re-request fulfilled
- **WHEN** a re-request is fulfilled for a Secret that has active SecretShares
- **THEN** the server MUST flag that sync-on-update is needed for shared copies
- **THEN** the next time the requester opens the secret, the frontend MUST trigger sync-on-update to re-encrypt for all recipients

### Requirement: Revoke Pending Request
The requester MUST be able to revoke a pending SecretRequest. The behavior differs based on whether the request is a new request or a re-request:

**New request revocation:**
- Delete the SecretRequest record
- Delete the linked unfilled Secret (it has no useful data)
- Invalidate the token (404 on access)

**Re-request revocation:**
- Delete the SecretRequest record only
- Preserve the existing Secret and its current values
- Invalidate the token (404 on access)

Only pending requests MUST be revocable. Fulfilled requests cannot be revoked.

#### Scenario: Revoke pending new request
- **WHEN** the requester revokes a pending SecretRequest that created a new unfilled Secret
- **THEN** the SecretRequest MUST be deleted
- **THEN** the unfilled Secret MUST be deleted
- **THEN** the fill-in link MUST return 404

#### Scenario: Revoke pending re-request
- **WHEN** the requester revokes a pending re-request
- **THEN** the SecretRequest MUST be deleted
- **THEN** the existing Secret and its current values MUST be preserved
- **THEN** the fill-in link MUST return 404

#### Scenario: Attempt to revoke fulfilled request
- **WHEN** the requester attempts to revoke an already-fulfilled request
- **THEN** the system MUST return an error indicating the request cannot be revoked

### Requirement: Locked Status During Compromise Recovery
When the requester initiates compromise recovery (EncryptionSuite migration), all pending SecretRequests for the old suite MUST be set to `locked` status. While locked:
- The fill-in page MUST display "temporarily unavailable"
- Submissions MUST be rejected
- The request MUST NOT be revocable

After migration completes:
- Locked requests MUST be set back to `pending` status
- The `encryption_suite_id` MUST be updated to the new suite
- The fill-in page MUST resume normal operation, encrypting with the new public certificate

#### Scenario: Fill-in during compromise recovery
- **WHEN** an external party accesses a fill-in link for a locked request
- **THEN** the system MUST return a message indicating the request is temporarily unavailable
- **THEN** the system MUST NOT accept submissions

#### Scenario: Requests unlocked after migration
- **WHEN** the requester completes compromise recovery migration
- **THEN** all previously locked requests MUST be set to `pending`
- **THEN** the encryption_suite_id MUST be updated to the new suite
- **THEN** fill-in submissions MUST encrypt with the new suite's public certificate
