## ADDED Requirements

### Requirement: CACertificate Entity and Database Table
The system MUST store CA certificates in the `doriath_ca_certificates` table with the following fields:

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | integer (autoincrement) | No | Primary key |
| `type` | string (enum) | No | `root` or `intermediate` |
| `certificate` | text | No | PEM-encoded X.509 certificate |
| `private_key` | text | Yes (AES via ICrypto) | PEM private key encrypted with Nextcloud's `secret` via `OCP\Security\ICrypto`. Present for both root and intermediate. Root key is retained for signing new intermediates during renewal. |
| `created_at` | datetime | No | Set on creation |
| `expires_at` | datetime | No | Extracted from certificate, stored for efficient expiry queries |
| `is_active` | bool | No | Only one intermediate is active for signing at a time |
| `revoked_at` | datetime | No | Set on forced revocation; null otherwise |
| `successor_id` | integer (FK) | No | Points to the CACertificate that replaced this one; null if none |

The migration MUST use Nextcloud's `ISchemaWrapper` pattern. The intermediate private key is encrypted server-side via `OCP\Security\ICrypto` (not user AES key) because it must be decryptable server-side for certificate signing operations.

#### Scenario: Database table creation
- **WHEN** Nextcloud runs `occ upgrade` with Doriath installed
- **THEN** the `doriath_ca_certificates` table MUST be created with all specified columns and correct types

### Requirement: CA Bootstrap on First Setup
The system MUST generate a private Certificate Authority (root + intermediate) on first setup if no CA certificates exist. Bootstrap MUST run as a Nextcloud `IRepairStep` registered for `post-migration` in `info.xml`. The repair step class MUST be `BootstrapCertificateAuthority`.

The root certificate MUST have a 20-year lifetime. The intermediate certificate MUST have a 3-year lifetime and be signed by the root. Both the root and intermediate private keys MUST be encrypted via `OCP\Security\ICrypto` and stored. The root private key is retained in the database because it is needed for signing new intermediates during renewal operations.

**Future consideration (out of scope):** A future version may support exporting the root private key to an administrator for offline safekeeping on a hardware token or air-gapped device, after which it is purged from the database. Root operations (intermediate signing, root renewal) would then require the administrator to temporarily provide the key. This "offline root key" pattern follows high-security PKI best practice and minimizes long-term exposure of the root key in the database.

#### Scenario: CA bootstrap success
- **WHEN** the `BootstrapCertificateAuthority` repair step runs and no CA certificates exist
- **THEN** the system MUST generate a root certificate with a 20-year lifetime
- **AND** generate an intermediate certificate with a 3-year lifetime signed by the root
- **AND** store both certificates in the `doriath_ca_certificates` table
- **AND** store the intermediate's private key encrypted via `OCP\Security\ICrypto`
- **AND** store the root's private key encrypted via `OCP\Security\ICrypto`
- **AND** set the intermediate's `is_active` to true
- **AND** set the app config `ca_status` to `healthy`

#### Scenario: CA bootstrap failure — degraded state
- **WHEN** the bootstrap step encounters an error (database failure, insufficient entropy, OpenSSL error)
- **THEN** Doriath MUST install successfully but set app config `ca_status` to `degraded`
- **AND** all Doriath routes MUST display: "Doriath cannot run without a configured Certificate Authority"
- **AND** the admin settings panel MUST show CA status as "not configured" with a retry button

#### Scenario: Bootstrap is idempotent
- **WHEN** the repair step runs and CA certificates already exist
- **THEN** the system MUST skip bootstrap without error

### Requirement: CA Health Check in Admin Panel
The admin panel MUST display the current CA status at all times using the `CnSettingsSection` component.

| Status | Condition |
|--------|-----------|
| Not configured | `ca_status` app config is `degraded` or no CA certificates exist |
| Healthy | Active intermediate exists, not expiring within 30 days, root not expiring within 90 days |
| Expiring soon | Active intermediate expires within 30 days |
| Action required | Root expires within 90 days, or intermediate is revoked, or no active intermediate |

#### Scenario: Admin views healthy CA
- **WHEN** an admin opens the Doriath settings page and the CA is healthy
- **THEN** the admin panel MUST display status "Healthy" with intermediate expiry date and root expiry date

#### Scenario: Admin views degraded CA with retry
- **WHEN** an admin opens settings and `ca_status` is `degraded`
- **THEN** the panel MUST display "Not configured" with a "Retry Bootstrap" button
- **WHEN** the admin clicks retry
- **THEN** the system MUST attempt CA bootstrap again via the API

### Requirement: Intermediate Certificate Auto-Renewal
The system MUST automatically renew the intermediate certificate before it expires. A Nextcloud background job (`RenewIntermediateCertificate`) MUST run daily and check whether the active intermediate expires within 30 days. On renewal, all active EncryptionSuites MUST be re-signed with the new intermediate. The admin MUST be notified via `OCP\Notification\IManager`.

Re-signing means: extract the public key from the existing suite certificate, generate a new X.509 certificate signed by the new intermediate, and update the suite's `certificate` field. The RSA key pair and encrypted private key are untouched.

The old intermediate MUST be retained (with `is_active` set to false and `successor_id` pointing to the new intermediate) for certificate chain verification until its original expiry date.

#### Scenario: Intermediate auto-renewal triggers
- **WHEN** the background job runs and the active intermediate expires within 30 days
- **THEN** the system MUST generate a new intermediate certificate (3-year lifetime) signed by the root
- **AND** encrypt the new intermediate's private key via `OCP\Security\ICrypto`
- **AND** set the new intermediate as active and the old as inactive with `successor_id`
- **AND** re-sign all EncryptionSuites with status `active` using the new intermediate
- **AND** send a notification to all admin users

#### Scenario: Auto-renewal with many suites
- **WHEN** the instance has more than 100 active EncryptionSuites
- **THEN** re-signing MUST be performed in batches of 100 to avoid database lock contention

### Requirement: Root Certificate Expiry Notification
The system MUST notify administrators when the root certificate is approaching expiry. Notifications MUST be sent at 90, 30, and 7 days before root expiry via `OCP\Notification\IManager`. A background job (`CheckRootCertificateExpiry`) MUST run daily.

Root renewal is admin-triggered only — never automatic.

#### Scenario: Root expiry notification at 90 days
- **WHEN** the root certificate expires within 90 days
- **THEN** the system MUST send a notification to all admin users with the message "Doriath CA root certificate expires in X days"

#### Scenario: Root expiry notification at 7 days
- **WHEN** the root certificate expires within 7 days
- **THEN** the notification MUST have urgency "critical"

### Requirement: Admin-Triggered Root Renewal
An administrator MUST be able to trigger root certificate renewal from the admin panel. On root renewal: a new root certificate (20-year lifetime) is generated, a new intermediate is signed by the new root, and all active EncryptionSuites are re-signed with the new intermediate.

#### Scenario: Root renewal
- **WHEN** an admin triggers root renewal
- **THEN** the system MUST generate a new root certificate (20-year lifetime)
- **AND** generate a new intermediate certificate (3-year lifetime) signed by the new root
- **AND** re-sign all active EncryptionSuites with the new intermediate
- **AND** mark the old root and old intermediate with `successor_id` pointing to new certificates
- **AND** set the old intermediate's `is_active` to false

### Requirement: Forced Intermediate Renewal
An administrator MUST be able to force renewal of the intermediate certificate at any time (use case: leaked intermediate key, ownership transfer). Unlike auto-renewal, forced renewal MUST immediately revoke the old intermediate (set `revoked_at`), not retain it for verification.

#### Scenario: Forced intermediate renewal
- **WHEN** an admin triggers forced intermediate renewal
- **THEN** the system MUST generate a new intermediate certificate signed by the current root
- **AND** immediately set `revoked_at` on the old intermediate
- **AND** re-sign all active EncryptionSuites with the new intermediate
- **AND** notify the admin with a count of re-signed suites

### Requirement: CA Management API Endpoints
The system MUST expose REST API endpoints for CA management. All CA management endpoints MUST require admin authorization.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/ca/status` | Get CA health status |
| POST | `/api/v1/ca/bootstrap-retry` | Retry CA bootstrap |
| POST | `/api/v1/ca/renew-intermediate` | Force intermediate renewal |
| POST | `/api/v1/ca/renew-root` | Trigger root renewal |

#### Scenario: Non-admin cannot access CA endpoints
- **WHEN** a non-admin user calls any CA management endpoint
- **THEN** the API MUST return 403 Forbidden
