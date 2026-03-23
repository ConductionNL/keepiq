# Application Management Specification

**Status**: planned

**OpenSpec changes:** _(none yet)_

## Purpose

External and internal applications can be registered in Doriath so that secrets can be attributed to them. An application gets its own EncryptionSuite, allowing secrets to be encrypted specifically for that application. The application can then retrieve and decrypt its own secrets via the API.

Registration is open (anyone can register, even anonymously), but non-admin registrations go into a pending queue for approval by the vault administrator. Secrets cannot be attributed to a pending application.

## Data Model

### Application

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `name` | string | No | Human-readable name |
| `type` | enum | No | `internal` (Nextcloud app) or `external` |
| `status` | enum | No | `pending`, `active` |
| `registered_by` | string | No | Nextcloud user ID or null (anonymous) |
| `approved_by` | string | No | Admin user ID; null if pending |
| `created_at` | datetime | No | |
| `approved_at` | datetime | No | |

The Application's encryption identity is provided via its EncryptionSuite(s), linked via the polymorphic `owner_type = application` pattern (see ADR-002).

## Requirements

### Requirement: Register Application
The system MUST allow any user (including anonymous) to register an application.

#### Scenario: Admin registers application
- GIVEN the registrant is a Nextcloud administrator or vault app administrator
- WHEN they submit a registration
- THEN the application MUST be created with status `active` immediately

#### Scenario: Non-admin registers application
- GIVEN the registrant is a regular user or anonymous
- WHEN they submit a registration
- THEN the application MUST be created with status `pending`
- AND no secrets can be attributed to it until it is approved

### Requirement: Approval Queue
The system MUST allow vault administrators to view and approve or reject pending applications.

#### Scenario: Approve application
- GIVEN an application is pending
- WHEN a vault administrator approves it
- THEN the application status MUST be set to `active`

#### Scenario: Reject application
- GIVEN an application is pending
- WHEN a vault administrator rejects it
- THEN the application MUST be removed from the queue and deleted

### Requirement: EncryptionSuite via CSR
When a CSR is uploaded during registration, the system MUST use the public key from the CSR to create an EncryptionSuite for the application.

#### Scenario: Register with CSR
- GIVEN a registrant uploads a valid CSR
- WHEN the application is approved (or auto-approved for admin)
- THEN the system MUST extract the public key from the CSR, sign it with the CA intermediate, and create an EncryptionSuite for the application
- AND the private key is NOT stored — the application manages it externally

#### Scenario: Register without CSR
- GIVEN no CSR is uploaded
- WHEN the application is approved
- THEN the system MUST generate a 4096-bit RSA key pair, sign the certificate, and create an EncryptionSuite
- AND the private key MUST be returned to the registrant once (never stored in plaintext)

### Requirement: Attribute Secrets to Application
Once an application is active, users with appropriate permission MUST be able to write secrets into the application's vault (encrypted with the application's public certificate).

#### Scenario: Write secret for application
- GIVEN an application is active and has an EncryptionSuite
- WHEN a user with permission writes a secret for the application
- THEN the secret MUST be encrypted with the application's public certificate
- AND the writing user MUST NOT be able to read it back (write-without-read)

## User Stories

- As a developer, I want to register my application so that I can store and retrieve its secrets from Doriath
- As an administrator, I want to approve or reject application registrations so that I control which applications can use the vault
- As a user, I want to write a secret for an application without being able to read it so that sensitive values are never in my hands
- As an application, I want to retrieve my secrets via the API using my private key so that I can configure myself securely

## Acceptance Criteria

- [ ] Any user (including anonymous) can submit an application registration
- [ ] Admin-registered applications are immediately active
- [ ] Non-admin registrations are placed in a pending queue
- [ ] Vault administrators can approve or reject pending applications
- [ ] An approved application gets an EncryptionSuite (via CSR or generated)
- [ ] If a CSR is uploaded, the private key is not stored — only the signed certificate
- [ ] If no CSR is uploaded, a key pair is generated and the private key returned once
- [ ] Secrets cannot be attributed to pending applications
- [ ] Writing a secret for an application encrypts it with the app's public certificate

## Notes

- "Internal vs external" application distinction does not currently affect functionality — both types go through the same registration flow. The type field is informational for now.
- The master password challenge for internal applications (how does a Nextcloud app running a cronjob authenticate?) is a known open problem documented in the Vault-app.docx. It is not scoped in this spec.
- Related ADRs: ADR-002 (polymorphic ownership), ADR-003 (encryption architecture)
