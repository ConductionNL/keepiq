## Why

With EncryptionSuites (implement-encryption-suites) and Secrets (implement-secrets) in place, Doriath can store and retrieve encrypted secrets but has no mechanism for external parties to securely submit secret values. Secret requests enable write-without-read submission via fill-in links: a user creates a request specifying which fields are needed, receives a fill-in link, and shares it with an external party who submits the values encrypted with the requester's public certificate. The submitter never needs a Nextcloud account. The requester can only decrypt the submitted values with their master password. This is an MVP-tier feature (FEATURES.md) and a core differentiator — most competitors lack write-without-read submission.

## What Changes

- Implement SecretRequest entity with token, requested fields, status lifecycle (pending/locked/fulfilled), and optional expiry
- Implement SecretRequest creation flow: generates an unfilled Secret and a fill-in link token (own secrets and application secrets; not other users' secrets)
- Implement public fill-in page at `/share/request/:token` (no Nextcloud auth, no lock screen) where external parties submit values encrypted with the requester's public certificate via browser-side RSA encryption
- Implement write-once semantics: fill-in link invalidated after fulfillment, status set to fulfilled
- Implement re-request for credential rotation: new SecretRequest against an existing Secret, new values overwrite on fulfillment with sync-on-update propagation to shares
- Implement field validation: all requested fields must be submitted with non-empty values
- Implement optional request expiry: expired requests reject submissions
- Implement request revocation: revoke pending request (deletes unfilled Secret for new requests, preserves existing Secret for re-requests)
- Implement locked status during compromise recovery (fill-in temporarily unavailable while the requester's EncryptionSuite is migrating)
- Implement fulfillment notification via NotificationService + DoriathNotifier (from implement-user-sharing)
- Add database migration for `doriath_secret_requests` table with unique index on token
- Add public fill-in Vue page (SecretRequestFill) and management UI for creating/viewing/revoking requests

## Capabilities

### New Capabilities
- `secret-requests`: SecretRequest entity with fill-in link creation, public submission with browser-side RSA encryption, write-once semantics, re-request for credential rotation, field validation, optional expiry, revocation, locked status during compromise recovery, and fulfillment notification

### Modified Capabilities
- `secrets`: Secret entity gains awareness of secret requests — delete cascade must remove associated SecretRequests; re-request fulfillment overwrites existing Secret fields in place and triggers sync-on-update
- `encryption-suites`: EncryptionSuite compromise recovery must set SecretRequest status to `locked` (fill-in temporarily unavailable) and update `encryption_suite_id` to the new suite after migration completes

## Impact

- **Database**: One new table (`doriath_secret_requests`) via ISchemaWrapper migration with unique index on `token` and index on `secret_id`
- **Backend**: New entity (SecretRequest), mapper (SecretRequestMapper), service (SecretRequestService), controllers (SecretRequestController for authenticated CRUD, SecretRequestFillController for public fill-in endpoints)
- **Frontend**: New Pinia store (useSecretRequestStore), Vue components (SecretRequestFill public page, SecretRequestCreateDialog, SecretRequestList management), browser-side RSA encryption using existing `rsaEncrypt()` from `src/crypto/rsa.js`
- **API**: REST endpoints for request CRUD (authenticated) and public fill-in (token + encrypted values POST)
- **Dependencies**: Depends on implement-encryption-suites (EncryptionSuite entity, public certificates, crypto services) and implement-secrets (Secret entity, SecretService). Uses NotificationService and DoriathNotifier from implement-user-sharing for fulfillment notifications.
- **Cross-app**: OpenConnector can use secret requests to have vendors submit API credentials directly into application vaults without the admin seeing plaintext
- **Nextcloud integration**: OCP\Notification\IManager for fulfillment notifications (via existing NotificationService), #[PublicPage] attribute for public fill-in controller
- **Security**: The write-without-read property is a direct consequence of asymmetric encryption — the fill-in page encrypts with the requester's public certificate (available without auth), and only the requester's private key (which requires the master password) can decrypt. The server never sees plaintext during the fill-in flow. Token entropy (128+ bits via random_bytes) makes guessing infeasible.
