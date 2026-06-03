## 1. Database Migrations and Seed Data

- [x] 1.1 Create ISchemaWrapper migration `Version000001Date20260331000000` for `doriath_encryption_suites` table with all columns (id, owner_type, owner_id, certificate, private_key, status, revoked_at, revoked_reason, revoked_by, reinstated_at, reinstated_by, created_at)
- [x] 1.2 Create ISchemaWrapper migration `Version000002Date20260331000001` for `doriath_ca_certificates` table with all columns (id, type, certificate, private_key, created_at, expires_at, is_active, revoked_at, successor_id)
- [x] 1.3 Create ISchemaWrapper migration `Version000003Date20260331000002` for `doriath_suite_migrations` table with all columns (id, old_suite_id, new_suite_id, status, started_at, completed_at)
- [x] 1.4 Create `BootstrapCertificateAuthority` IRepairStep that generates root (20yr) + intermediate (3yr) certificates, stores them in `doriath_ca_certificates`, encrypts intermediate private key via `ICrypto`, discards root private key, sets `ca_status` app config
- [x] 1.5 Register `BootstrapCertificateAuthority` as post-migration repair step in `info.xml`
- [x] 1.6 Update `InitializeSettings` repair step to seed default app config values: `master_password_min_length=12`, `master_password_min_score=3`, `session_timeout_default=600000`
- [x] 1.7 Create `SeedDevelopmentData` repair step (debug-only) that bootstraps CA and creates a test user EncryptionSuite with known master password for development

## 2. Entities and Mappers

- [x] 2.1 Create `EncryptionSuite` Doctrine entity in `lib/Db/EncryptionSuite.php` with all fields, JsonSerializable, and column type annotations
- [x] 2.2 Create `EncryptionSuiteMapper` extending QBMapper in `lib/Db/EncryptionSuiteMapper.php` with methods: findByOwner(type, id), findActiveByOwner(type, id), findById(id)
- [x] 2.3 Create `CACertificate` Doctrine entity in `lib/Db/CACertificate.php` with all fields and JsonSerializable
- [x] 2.4 Create `CACertificateMapper` extending QBMapper in `lib/Db/CACertificateMapper.php` with methods: findActiveIntermediate(), findRoot(), findExpiringSoon(days)
- [x] 2.5 Create `SuiteMigration` Doctrine entity in `lib/Db/SuiteMigration.php` with all fields and JsonSerializable
- [x] 2.6 Create `SuiteMigrationMapper` extending QBMapper in `lib/Db/SuiteMigrationMapper.php` with methods: findInProgressByOwner(ownerId), findBySuiteId(suiteId)

## 3. Stateless Crypto Services (PHP)

- [x] 3.1 Create `EncryptService` in `lib/Service/EncryptService.php` with methods: rsaEncrypt(plaintext, publicKeyPem), aesEncrypt(plaintext, key), encryptPrivateKey(pem, aesKey) — all stateless, no DB access
- [x] 3.2 Create `DecryptService` in `lib/Service/DecryptService.php` with methods: rsaDecrypt(ciphertext, privateKeyPem), aesDecrypt(envelope, key), decryptPrivateKey(blob, aesKey) — all stateless, no DB access
- [x] 3.3 Create `DecryptionException` in `lib/Exception/DecryptionException.php` for GCM auth failures and invalid format errors
- [x] 3.4 Implement RSA-OAEP-SHA256 chunking in EncryptService: split plaintext into 446-byte chunks, encrypt each to 512-byte blocks, prepend 4-byte chunk count
- [x] 3.5 Implement RSA-OAEP-SHA256 chunk parsing in DecryptService: read 4-byte chunk count, decrypt each 512-byte block, concatenate plaintext
- [x] 3.6 Implement AES-256-GCM envelope format in both services: version(0x01) + salt(16) + IV(12) + ciphertext + tag(16), base64 encoded

## 4. Business Logic Services (PHP)

- [x] 4.1 Create `CertificateAuthorityService` in `lib/Service/CertificateAuthorityService.php` with methods: bootstrap(), signPublicKey(publicKeyPem), renewIntermediate(forced), renewRoot(), getStatus(), retryBootstrap()
- [x] 4.2 Implement CA bootstrap logic: generate root cert (20yr), generate intermediate cert (3yr) signed by root, encrypt intermediate private key via ICrypto, discard root private key, set ca_status
- [x] 4.3 Implement certificate signing: extract subject from public key PEM, create X.509 cert signed by active intermediate, return PEM certificate
- [x] 4.4 Implement intermediate renewal: generate new intermediate, re-sign all active suites in batches of 100, handle forced vs auto-renewal (revoke old vs retain)
- [x] 4.5 Create `EncryptionSuiteService` in `lib/Service/EncryptionSuiteService.php` with methods: createSuite(ownerType, ownerId, publicKeyPem, encryptedPrivateKey), revokeSuite(id, reason, revokedBy), reinstateSuite(id, reinstatedBy), getActiveSuite(ownerType, ownerId)
- [x] 4.6 Implement suite creation: validate CA is healthy, sign public key, store encrypted private key, set status=active
- [x] 4.7 Implement revocation: set status=revoked, record audit fields, validate not already compromised
- [x] 4.8 Implement reinstatement: validate status=revoked (not compromised), re-sign public key with active intermediate, set status=active, record reinstated_at/by, preserve revocation audit
- [x] 4.9 Create `MigrationService` in `lib/Service/MigrationService.php` with methods: initiateCompromiseRecovery(oldSuiteId, newSuiteId), completeMigration(migrationId), getInProgressMigration(ownerId), isWriteLocked(ownerId)
- [x] 4.10 Implement write lock: check for in_progress SuiteMigration before allowing secret create/update operations

## 5. Controllers and API Routes

- [x] 5.1 Create `EncryptionSuiteController` extending OCSController in `lib/Controller/EncryptionSuiteController.php` with endpoints: list, get, create, updatePrivateKey, revoke, reinstate, compromiseRecovery
- [x] 5.2 Create `CACertificateController` extending OCSController in `lib/Controller/CACertificateController.php` with admin-only endpoints: getStatus, retryBootstrap, renewIntermediate, renewRoot
- [x] 5.3 Create `MigrationController` extending OCSController in `lib/Controller/MigrationController.php` with endpoints: getStatus, complete
- [x] 5.4 Register all API routes in `appinfo/routes.php` under `/api/v1/` prefix
- [x] 5.5 Add admin authorization checks on all CA management endpoints
- [x] 5.6 Add owner authorization checks: users can only access their own suites (admins can access any)

## 6. Background Jobs

- [x] 6.1 Create `RenewIntermediateCertificate` background job in `lib/BackgroundJob/RenewIntermediateCertificate.php` — daily check, renew if within 30 days of expiry, notify admins via IManager
- [x] 6.2 Create `CheckRootCertificateExpiry` background job in `lib/BackgroundJob/CheckRootCertificateExpiry.php` — daily check, notify admins at 90/30/7 days, critical urgency at 7 days
- [x] 6.3 Register both background jobs in `info.xml`

## 7. WebCrypto Utility Module (Frontend)

- [x] 7.1 Create `src/crypto/aes.js` with functions: deriveAesKey(masterPassword, salt), encryptPrivateKey(pem, aesKey), decryptPrivateKey(envelope, aesKey) — using crypto.subtle
- [x] 7.2 Create `src/crypto/rsa.js` with functions: generateKeyPair(), rsaEncrypt(plaintext, publicKey), rsaDecrypt(ciphertext, privateKey), importPrivateKey(pem) — using crypto.subtle with extractable:false
- [x] 7.3 Create `src/crypto/envelope.js` with functions: encodeEnvelope(version, salt, iv, ciphertext, tag), decodeEnvelope(base64) — shared format parsing
- [x] 7.4 Create `src/crypto/index.js` barrel export for all crypto functions
- [x] 7.5 Ensure chunking format matches PHP implementation: 4-byte chunk count + 512-byte blocks for RSA-OAEP-SHA256

## 8. Pinia Stores (Frontend)

- [x] 8.1 Create `src/store/modules/session.js` Pinia store with state: cryptoKey, aesKey, timeout, lastActivity; getters: isLocked; actions: unlock(masterPassword), lock(), checkTimeout(), updateActivity()
- [x] 8.2 Create `src/store/modules/encryptionSuite.js` Pinia store with state: currentSuite, migrationStatus; actions: fetchSuite(), createSuite(masterPassword), changePassword(oldPw, newPw), initiateCompromiseRecovery(oldPw, newPw), migrateSingleSecret(secretId)
- [x] 8.3 Integrate session timeout configuration from user settings (Nextcloud session / 10 min / 30 min)
- [x] 8.4 Implement activity tracking: update lastActivity on route changes and API calls within Doriath

## 9. Lock Screen and Navigation Guard (Frontend)

- [x] 9.1 Create `src/views/LockScreen.vue` with master password input, submit button, Doriath branding — full page, no vault content visible
- [x] 9.2 Add first-time setup mode to LockScreen: "Set up your master password" with PasswordStrengthMeter and confirmation field
- [x] 9.3 Add migration-paused mode to LockScreen: show remaining secret count, require master password to resume
- [x] 9.4 Add Vue Router `beforeEach` navigation guard in `src/router/index.js`: redirect to /lock if session.isLocked and target is not /lock; redirect to dashboard if unlocked and target is /lock
- [x] 9.5 Implement deep link preservation: store intended route before lock redirect, navigate there after successful unlock
- [x] 9.6 Register `setInterval` (10s) for timeout checks, `visibilitychange` listener, and `beforeunload` listener in the session store or App.vue

## 10. Master Password UI Components (Frontend)

- [x] 10.1 Add `zxcvbn` npm dependency to `package.json`
- [x] 10.2 Create `src/components/PasswordStrengthMeter.vue`: debounced input (300ms), colored bar (red/orange/green), text feedback from zxcvbn, character count, `strength-change` event emission
- [x] 10.3 Create `src/components/MasterPasswordForm.vue`: current password input, new password input with PasswordStrengthMeter, confirm field, submit disabled until strength floor met
- [x] 10.4 Create `src/components/CompromiseRecoveryForm.vue`: old password input, new password input with PasswordStrengthMeter, warning about key rotation implications, progress indicator during migration

## 11. Admin Settings UI (Frontend)

- [x] 11.1 Add CA health status display to admin settings page using `CnSettingsSection`: status badge (not configured / healthy / expiring soon / action required), root expiry date, intermediate expiry date
- [x] 11.2 Add retry bootstrap button (visible when ca_status = degraded)
- [x] 11.3 Add force renew intermediate button with confirmation dialog
- [x] 11.4 Add root renewal button with confirmation dialog (visible when root approaching expiry)
- [x] 11.5 Add master password policy settings: minimum length slider (12-20), minimum score selector (3-4)

## 12. User Settings UI (Frontend)

- [x] 12.1 Add session timeout selector to user settings: Nextcloud session / 10 minutes / 30 minutes
- [x] 12.2 Add "Change master password" button linking to MasterPasswordForm
- [x] 12.3 Add "My master password was compromised" button linking to CompromiseRecoveryForm
- [x] 12.4 Add EncryptionSuite status display: active/revoked/compromised, created date, certificate expiry

## 13. Internationalization

- [x] 13.1 Add English translations for all new UI strings (lock screen, strength meter, admin settings, error messages) in `l10n/en.json` or equivalent
- [x] 13.2 Add Dutch translations for all new UI strings in `l10n/nl.json` or equivalent
- [x] 13.3 Use `t()` / `n()` translation functions in all Vue components and PHP controllers

## 14. Unit Tests

- [x] 14.1 Write unit tests for `EncryptService`: single-chunk RSA, multi-chunk RSA, AES-256-GCM envelope encode/decode
- [x] 14.2 Write unit tests for `DecryptService`: single-chunk RSA, multi-chunk RSA, AES-256-GCM envelope decode, GCM tag validation failure
- [x] 14.3 Write cross-implementation round-trip tests: encrypt in PHP -> decrypt in PHP, varying plaintext sizes (1 byte, 446 bytes, 1000 bytes, 10000 bytes)
- [x] 14.4 Write unit tests for `CertificateAuthorityService`: bootstrap generates valid root + intermediate, signing produces valid X.509 cert, renewal logic
- [x] 14.5 Write unit tests for `EncryptionSuiteService`: create, revoke, reinstate, reinstate-compromised-rejected
- [x] 14.6 Write unit tests for `MigrationService`: initiate, complete, write lock check, in-progress detection

## 15. Integration Tests

- [x] 15.1 Write integration tests for EncryptionSuite API: create suite, list suites, update private key, revoke, reinstate
- [x] 15.2 Write integration tests for CA API: get status, retry bootstrap, force renew intermediate
- [x] 15.3 Write integration tests for Migration API: get migration status, complete migration
- [x] 15.4 Write integration test: API never returns decrypted private key material — verify private_key field in response is the AES-encrypted blob
- [x] 15.5 Write integration test: non-admin cannot access CA management endpoints (403)
- [x] 15.6 Write integration test: user cannot access another user's EncryptionSuite (403)
- [x] 15.7 Write integration test for cross-implementation encryption round-trip: encrypt with EncryptService PHP, verify format is parseable by JS WebCrypto (format-level test with known test vectors)

## 16. Frontend Tests

- [x] 16.1 Write unit tests for WebCrypto utility functions: AES key derivation, envelope encode/decode, RSA chunking
- [x] 16.2 Write unit tests for session store: unlock, lock, timeout check, activity tracking
- [x] 16.3 Write unit tests for PasswordStrengthMeter: score calculation, submit enable/disable, feedback display
- [x] 16.4 Write component tests for LockScreen: first-time setup mode, normal unlock mode, migration-paused mode
