# Secrets — Delta Spec

**Change:** implement-application-mgmt

**Base spec:** `openspec/specs/secrets/spec.md`

## Modified Behavior

### Application-Owned Secrets

Secrets can now have `owner_type=application` and `owner_id={application_id}`. These secrets are:

1. **Created by users** — a user writes a secret for an application, encrypting the value with the application's public certificate (write-without-read)
2. **Read by applications** — the application retrieves encrypted blobs via the JWT-authenticated API and decrypts locally with its own private key
3. **Not readable by the creating user** — the user does not have the application's private key and cannot decrypt

### Write-Without-Read Semantics

When creating a secret with `owner_type=application`:
- The API validates that the referenced application exists and has `status=active`
- The API validates that an EncryptionSuite exists for the application
- The `encryption_suite_id` is set to the application's EncryptionSuite
- The encrypted fields (key, login, additional_fields) are encrypted with the application's public certificate by the browser before submission
- The creating user's identity is not stored on the secret (the secret belongs to the application)

### Cascade Deletion

When an application is deleted, all secrets with `owner_type=application` AND `owner_id={application_id}` are hard-deleted. Associated SecretRequests are also deleted.
