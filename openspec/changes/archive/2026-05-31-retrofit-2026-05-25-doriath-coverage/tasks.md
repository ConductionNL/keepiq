# Tasks — Retrofit coverage annotation for Doriath

All tasks are `[x]`: the code already ships. Each task maps a cluster of observed methods to the existing capability spec + REQ it realizes. Method docblocks carry `@spec ...#task-N` references back here.

## task-1: Certificate Authority lifecycle
Realizes `encryption-suites` REQs **CA Bootstrap**, **CA Certificate Renewal**, **CA Health Check**, **Certificate Distinguished Name**, **Minimum Key Size**.
- [x] `lib/Service/CertificateAuthorityService.php::bootstrap` — generate root (20y) + intermediate (3y), store intermediate key AES-encrypted; degraded state on failure.
- [x] `lib/Service/CertificateAuthorityService.php::retryBootstrap` — admin retry of a failed bootstrap.
- [x] `lib/Service/CertificateAuthorityService.php::signPublicKey` — sign a user/app public key with the active intermediate using the X.509 DN.
- [x] `lib/Service/CertificateAuthorityService.php::signCsr` — sign a submitted CSR for application registration.
- [x] `lib/Service/CertificateAuthorityService.php::renewIntermediate` — auto/forced intermediate renewal; re-sign all active suites.
- [x] `lib/Service/CertificateAuthorityService.php::renewRoot` — admin-triggered root renewal with new intermediate.
- [x] `lib/Service/CertificateAuthorityService.php::getStatus` — derive CA health (healthy / expiring / action-required / not-configured).
- [x] `lib/Controller/CACertificateController.php::renewIntermediate` — HTTP endpoint for forced intermediate renewal.
- [x] `lib/Controller/CACertificateController.php::renewRoot` — HTTP endpoint for root renewal.
- [x] `lib/Controller/CACertificateController.php::retryBootstrap` — HTTP endpoint for bootstrap retry.
- [x] `lib/BackgroundJob/CheckRootCertificateExpiry.php::run` — notify admins at 90/30/7 days before root expiry.
- [x] `lib/BackgroundJob/RenewIntermediateCertificate.php::run` — auto-renew intermediate before expiry, re-sign suites, notify admin.
- [x] `lib/Repair/BootstrapCertificateAuthority.php::run` — bootstrap CA on first install via repair step.

## task-2: EncryptionSuite lifecycle
Realizes `encryption-suites` REQs **Suite Creation on First Login**, **Revocation**, **Reinstatement**, **Master Password Change — Compromise Recovery**.
- [x] `lib/Service/EncryptionSuiteService.php::createSuite` — create a suite, sign public key, store AES-encrypted private key.
- [x] `lib/Service/EncryptionSuiteService.php::revokeSuite` — set status revoked with reason/timestamp/revoker.
- [x] `lib/Service/EncryptionSuiteService.php::reinstateSuite` — re-sign existing public key, set active, preserve audit fields.
- [x] `lib/Service/EncryptionSuiteService.php::markCompromised` — flag suite compromised after migration.
- [x] `lib/Service/EncryptionSuiteService.php::updateSuite` — persist suite mutations.
- [x] `lib/Controller/EncryptionSuiteController.php::index` — list the caller's suites.
- [x] `lib/Controller/EncryptionSuiteController.php::show` — fetch one suite (ownership-checked).
- [x] `lib/Controller/EncryptionSuiteController.php::create` — create suite for the authenticated owner.
- [x] `lib/Controller/EncryptionSuiteController.php::updatePrivateKey` — store re-wrapped encrypted private key (routine password change).
- [x] `lib/Controller/EncryptionSuiteController.php::revoke` — revoke endpoint.
- [x] `lib/Controller/EncryptionSuiteController.php::reinstate` — reinstate endpoint (admin).
- [x] `lib/Controller/EncryptionSuiteController.php::compromiseRecovery` — initiate full key rotation + migration.

## task-3: Crypto services (PHP/OpenSSL)
Realizes `encryption-suites` REQ **Session Mechanism** / Notes (DecryptService/EncryptService for internal app access) and the AES-256 + RSA-4096 private-key-wrapping data model.
- [x] `lib/Service/EncryptService.php::rsaEncrypt` — RSA-encrypt for a public key.
- [x] `lib/Service/EncryptService.php::aesEncrypt` — AES-256 envelope encryption.
- [x] `lib/Service/EncryptService.php::encryptPrivateKey` — wrap a PEM private key under a password-derived key.
- [x] `lib/Service/EncryptService.php::deriveKey` — derive an AES key from password + salt.
- [x] `lib/Service/DecryptService.php::rsaDecrypt` — RSA-decrypt with a private key.
- [x] `lib/Service/DecryptService.php::aesDecrypt` — AES-256 envelope decryption.
- [x] `lib/Service/DecryptService.php::decryptPrivateKey` — unwrap a stored private key with a password.
- [x] `lib/Service/DecryptService.php::deriveKey` — derive the same AES key for decryption.

## task-4: Suite migration & compromise recovery state
Realizes `encryption-suites` REQ **Suite Migration** and `dashboard` REQ **Migration Status Banner**.
- [x] `lib/Service/MigrationService.php::initiateCompromiseRecovery` — create SuiteMigration (in_progress), apply write lock.
- [x] `lib/Service/MigrationService.php::completeMigration` — finalize migration (completed / completed_with_errors), release lock.
- [x] `lib/Service/MigrationService.php::getInProgressMigration` — find an active migration for an owner.
- [x] `lib/Service/MigrationService.php::isWriteLocked` — report whether a write lock is active for an owner.
- [x] `lib/Controller/MigrationController.php::getStatus` — expose migration status to the browser.
- [x] `lib/Controller/MigrationController.php::complete` — mark a migration complete from the browser.

## task-5: Settings load / get / update
Realizes `admin-settings` REQ **Master Password Policy** / **CA Health Display** and `user-settings` REQ **User Settings Dialog**, **Session Timeout Preference**, **Notification Toggles**.
- [x] `lib/Service/SettingsService.php::getSettings` — read merged admin + user settings.
- [x] `lib/Service/SettingsService.php::updateSettings` — validate and persist settings (enforce policy floors).
- [x] `lib/Controller/SettingsController.php::index` — return settings to the frontend.
- [x] `lib/Controller/SettingsController.php::create` — validate + persist updated settings.
- [x] `lib/Controller/SettingsController.php::load` — trigger configuration load.

## task-6: Application registration & environment provisioning
Realizes `application-mgmt` REQs **Register Application** / **EncryptionSuite via CSR** and the install-time provisioning that makes registers/schemas/dev-data available.
- [x] `lib/Service/SettingsService.php::loadConfiguration` — import register/schema configuration via OpenRegister (ADR-022).
- [x] `lib/Repair/InitializeSettings.php::run` — seed default settings and register/schema configuration on install.
- [x] `lib/Repair/SeedDevelopmentData.php::run` — seed sample objects in a development environment.

## task-7: Lock screen & client-side session mechanism
Realizes `encryption-suites` REQ **Session Mechanism**, **Master Password Strength**, **Master Password Change — Routine** and `user-settings` REQ **Session Timeout Preference**.
- [x] `src/store/modules/session.js::unlock` — derive AES key, decrypt private key into an in-memory CryptoKey.
- [x] `src/store/modules/session.js::lock` — clear the in-memory CryptoKey.
- [x] `src/store/modules/session.js::checkTimeout` — clear key + redirect when the timeout elapses.
- [x] `src/store/modules/session.js::updateActivity` — record last-activity for timeout tracking.
- [x] `src/store/modules/encryptionSuite.js::fetchSuite` — fetch the caller's active suite blob.
- [x] `src/store/modules/encryptionSuite.js::createSuite` — create a suite on first setup.
- [x] `src/store/modules/encryptionSuite.js::changePassword` — routine re-wrap of the private key.
- [x] `src/store/modules/encryptionSuite.js::initiateCompromiseRecovery` — start key rotation from the browser.
- [x] `src/store/modules/encryptionSuite.js::revokeSuite` — revoke from the browser.
- [x] `src/store/modules/encryptionSuite.js::fetchMigrationStatus` — poll migration progress.
- [x] `src/views/LockScreen.vue::handleUnlock` — submit master password to unlock.
- [x] `src/views/LockScreen.vue::handleSetup` — first-time suite setup.
- [x] `src/views/LockScreen.vue::canSubmitSetup` — gate setup submit on strength validity.
- [x] `src/views/LockScreen.vue::onStrengthChange` — track strength validity.
- [x] `src/views/LockScreen.vue::created` — route to setup vs unlock on mount.
- [x] `src/App.vue::isLocked` (computed + watcher) — redirect to lock screen when locked.
- [x] `src/App.vue::permissions` — expose user permissions to the shell.
- [x] `src/App.vue::handleRevoke` — handle suite revocation from the shell.
- [x] `src/App.vue::handleVisibilityChange` — re-check timeout on tab focus.
- [x] `src/App.vue::handleBeforeUnload` — persist/cleanup on unload.
- [x] `src/App.vue::saveTimeout` — persist the session-timeout preference.
- [x] `src/App.vue::translateForApp` — app-scoped i18n helper used by the shell.
- [x] `src/App.vue::$route` (watcher), `created`, `beforeDestroy` — session-guard wiring driving the lock-screen redirect + timeout interval.
- [x] `src/components/MasterPasswordForm.vue::canSubmit` / `handleSubmit` / `onStrengthChange` — routine master-password change form.
- [x] `src/components/CompromiseRecoveryForm.vue::canSubmit` / `handleSubmit` / `onStrengthChange` — compromise-recovery form.
- [x] `src/components/PasswordStrengthMeter.vue::evaluate` / `feedbackText` / `scoreClass` / `password` — zxcvbn live strength feedback.

## task-8: Dashboard data feeds & settings UI
Realizes `dashboard` REQ **Vault Summary Cards** / **CA Health Status Card** and `admin-settings` REQ **CA Health Display** / **Master Password Policy**.
- [x] `src/store/modules/object.js::configure` — set object-store base URLs.
- [x] `src/store/modules/object.js::registerObjectType` — register a schema/register for an object type.
- [x] `src/store/modules/object.js::fetchObjects` — fetch objects feeding the dashboard summary cards.
- [x] `src/store/modules/settings.js::fetchSettings` — load settings for the dashboard/settings UI.
- [x] `src/store/modules/settings.js::saveSettings` — persist settings from the UI.
- [x] `src/store/store.js::initializeStores` — bootstrap Pinia stores from server initial state.
- [x] `src/components/settings/CaHealthSection.vue::fetchStatus` — load CA health for the admin section.
- [x] `src/components/settings/CaHealthSection.vue::retryBootstrap` — admin retry bootstrap action.
- [x] `src/components/settings/CaHealthSection.vue::forceRenewIntermediate` — admin force-renew action.
- [x] `src/components/settings/CaHealthSection.vue::statusClass` / `statusLabel` — map CA status to display state.
- [x] `src/components/settings/PasswordPolicySection.vue::created` / `save` — load + persist the master-password policy.
- [x] `src/views/settings/AdminRoot.vue::created` — load admin-settings state on mount.

## task-9: Unified search deep-link registration
Realizes `secrets` REQ **Nextcloud Unified Search Integration**.
- [x] `lib/Listener/DeepLinkRegistrationListener.php::handle` — register Doriath deep-link URL patterns with OpenRegister's search provider.
