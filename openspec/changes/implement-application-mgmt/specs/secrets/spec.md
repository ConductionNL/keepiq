# Secrets — Delta Spec

**Change:** implement-application-mgmt

**Base spec:** `openspec/specs/secrets/spec.md`

## MODIFIED Requirements

### Requirement: Application-Owned Secrets

Secrets MUST support `owner_type=application` and `owner_id={application_id}`. Such a secret is created by a user (encrypting the value with the application's public certificate, write-without-read), read by the application (which retrieves encrypted blobs via the JWT-authenticated API and decrypts locally with its own private key), and MUST NOT be readable by the creating user (who does not hold the application's private key).

#### Scenario: User writes a secret an application can read
- GIVEN an active application with an EncryptionSuite
- WHEN a user creates a secret with `owner_type=application` and `owner_id={application_id}`
- THEN the value MUST be encrypted with the application's public certificate
- AND the application MUST be able to retrieve and decrypt it via the JWT-authenticated API
- AND the creating user MUST NOT be able to decrypt it

### Requirement: Write-Without-Read Semantics

When creating a secret with `owner_type=application`, the API MUST validate that the referenced application exists and has `status=active`, MUST validate that an EncryptionSuite exists for the application, MUST set `encryption_suite_id` to that suite, MUST accept encrypted fields (key, login, additional_fields) encrypted with the application's public certificate by the browser before submission, and MUST NOT store the creating user's identity on the secret.

#### Scenario: Reject write to inactive application
- GIVEN an application that does not exist or is not `status=active`
- WHEN a user attempts to create a secret with `owner_type=application` for it
- THEN the API MUST reject the request

#### Scenario: Creating user identity not recorded
- GIVEN a user creates a secret with `owner_type=application`
- WHEN the secret is persisted
- THEN the creating user's identity MUST NOT be stored on the secret

### Requirement: Cascade Deletion of Application Secrets

When an application is deleted, all secrets with `owner_type=application` AND `owner_id={application_id}` MUST be hard-deleted, and their associated SecretRequests MUST also be deleted.

#### Scenario: Delete application cascades to its secrets
- GIVEN an application owns one or more secrets
- WHEN the application is deleted
- THEN all secrets with that `owner_id` MUST be hard-deleted
- AND their associated SecretRequests MUST be deleted
