## Why

Doriath cannot store or share any secrets until its cryptographic infrastructure exists. The EncryptionSuite — an RSA-4096 key pair with an X.509 certificate signed by a private CA — is the foundation that every other feature (secrets CRUD, sharing, application credentials) depends on. Without it, the app has no encryption capability. This is the MVP-tier blocker.

## What Changes

- Bootstrap a private Certificate Authority (root 20-year + intermediate 3-year) on first install via Nextcloud repair step
- Implement EncryptionSuite CRUD: auto-create on first user login, admin management of suites
- Implement master password session management entirely client-side (WebCrypto AES key derivation, CryptoKey in JS memory, configurable timeout)
- Add lock screen as a Vue Router navigation guard (full page, redirects when no CryptoKey in memory)
- Enforce master password strength with zxcvbn (live feedback, admin-configurable floor)
- Support routine master password change (re-wrap private key, no key rotation)
- Support compromise recovery (full RSA key rotation, browser-driven secret migration, write lock, SuiteMigration tracking)
- Implement suite revocation (admin/user) and reinstatement (admin-only, re-sign existing public key)
- Implement CA certificate renewal: intermediate auto-renewal, admin-triggered root renewal, forced intermediate renewal
- Create DecryptService and EncryptService as stateless PHP crypto utilities for internal Nextcloud app consumption
- Add database migrations for `doriath_encryption_suites`, `doriath_ca_certificates`, and `doriath_suite_migrations` tables
- Implement dual encryption: PHP/OpenSSL backend and JS/WebCrypto frontend with identical ciphertext format

## Capabilities

### New Capabilities
- `encryption-suites`: RSA-4096 key pair lifecycle — creation, revocation, reinstatement, compromise recovery, and suite migration with error tracking
- `ca-management`: Private Certificate Authority bootstrap, health monitoring, intermediate auto-renewal, root manual renewal, and forced renewal
- `master-password`: Client-side master password entry, AES key derivation, strength enforcement (zxcvbn), routine change, and session timeout management
- `lock-screen`: Full-page lock screen with Vue Router guard, session expiry detection, and tab-close cleanup
- `crypto-services`: Stateless DecryptService and EncryptService (PHP/OpenSSL) for internal Nextcloud app consumption, plus JS/WebCrypto equivalents for browser-side operations

### Modified Capabilities
_(none — this is the first implementation of encryption infrastructure)_

## Impact

- **Database**: Three new tables (`doriath_encryption_suites`, `doriath_ca_certificates`, `doriath_suite_migrations`) via ISchemaWrapper migrations
- **Backend**: New entities (EncryptionSuite, CACertificate, SuiteMigration), services (EncryptionSuiteService, CertificateAuthorityService, DecryptService, EncryptService, MigrationService), controllers (EncryptionSuiteController, CACertificateController), and a repair step for CA bootstrap
- **Frontend**: New Pinia stores (encryption suite store, session store), Vue components (lock screen, master password form, strength meter, migration progress), Vue Router guards, and WebCrypto utility modules
- **API**: New REST endpoints for suite CRUD, CA status, master password change, compromise recovery, migration progress, revocation, and reinstatement
- **Dependencies**: zxcvbn npm package for password strength scoring
- **Cross-app**: OpenConnector will consume EncryptionSuites via the application ownership model (ADR-002) and the DecryptService/EncryptService utilities
- **Security**: Master password and AES-derived key never leave the browser (ADR-003). Private keys are always AES-256 encrypted at rest. No server-side session state for encryption.
