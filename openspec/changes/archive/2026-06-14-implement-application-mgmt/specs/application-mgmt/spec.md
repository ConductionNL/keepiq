# Application Management — Delta Spec

**Change:** implement-application-mgmt

**Base spec:** `openspec/specs/application-mgmt/spec.md`

## New Fields

The Application entity gains two fields not in the base spec:

| Field | Type | Notes |
|-------|------|-------|
| `description` | text (nullable) | Optional description of the application's purpose |
| `csr` | text (nullable) | Temporary CSR storage for pending applications; cleared after approval |

The `description` field is stored in plaintext and returned in API responses. The `csr` field is internal-only (excluded from JsonSerializable output).

## ADDED Requirements

### Requirement: RFC 7523 JWT Bearer API Authentication

Applications MUST authenticate to Doriath's REST API using RFC 7523 (JWT Bearer assertion grant).

#### Scenario: Exchange JWT assertion for access token
- GIVEN an active application with an EncryptionSuite
- WHEN the application POSTs a signed JWT assertion to `/api/v1/token` with `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer`
- THEN the server MUST verify the JWT signature against the application's public certificate
- AND validate claims (aud=doriath, exp > now, iat <= now, jti not replayed)
- AND return a short-lived opaque access token (5-minute TTL)

#### Scenario: Retrieve secrets via Bearer token
- GIVEN a valid access token for an active application
- WHEN the application GETs `/api/v1/app/secrets` with `Authorization: Bearer {token}`
- THEN the server MUST return the application's secrets (encrypted blobs)
- AND the application decrypts locally with its private key

#### Scenario: Invalid JWT rejected
- GIVEN a JWT with an invalid signature, expired claims, or replayed jti
- WHEN the application POSTs the assertion to `/api/v1/token`
- THEN the server MUST return 401 Unauthorized

### Requirement: Anonymous Registration

The registration endpoint MUST accept requests without a Nextcloud session (anonymous).

#### Scenario: Anonymous registration
- GIVEN no Nextcloud session (anonymous user)
- WHEN a registration request is submitted
- THEN the application MUST be created with `status=pending` and `registered_by=null`
- AND all vault administrators MUST be notified

## Clarifications

### CSR Processing
The CSR (PKCS#10) is used solely as a transport mechanism for the public key. The CSR's subject (CN, O, etc.) is ignored. Doriath generates its own certificate subject: `CN=app:{application_id}`. The minimum accepted key size in a CSR is 4096 bits.

### Generated Key Pair Security
When no CSR is provided, the generated private key is returned exactly once in the API response body. It is never stored in the database (the EncryptionSuite's `private_key` field is null for application suites). If lost, re-registration is required.

### Deletion Cascade Order
1. Secrets (owner_type=application, owner_id=app.id)
2. SecretRequests for those secrets
3. EncryptionSuite (owner_type=application, owner_id=app.id)
4. Application record

All in a single database transaction.

### JWT Library
Implementation MUST use `web-token/jwt-framework` (NOT firebase/php-jwt). This is mandatory for conformity across all Conduction apps.
