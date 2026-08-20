# Tasks: Certificate Lifecycle

## 1. Data layer

- [x] 1.1 Migration: `doriath_certificate_metadata` table (`id`, `secret_id` FK+unique, `owner_id`, `subject` text, `issuer` text, `serial`, `fingerprint_sha256`, `not_before` nullable, `not_after` nullable, `parsed_at`) — `Version000029Date20260718200000`
- [x] 1.2 `CertificateMetadata` entity + `CertificateMetadataMapper` (standard `QBMapper`); `findBySecretId`, `findByOwner` (keyed by secret id), `deleteBySecretId`

## 2. Service layer

- [x] 2.1 `CertificateLifecycleService::inventory(userId, isAdmin)` — merges stored certificate-type secrets (client-parsed metadata rows), the caller's active suite cert (admins: all active suites), and (admin) CA root/intermediate; each row tagged `metadataSource: client_parsed|server_parsed`; no PEM, private key, or ciphertext ever emitted (regression-locked in tests)
- [x] 2.2 `CertificateLifecycleService::parseCaCertificate(pem)` — server-side `openssl_x509_parse` + sha256 fingerprint of cleartext PEM (suite/app/CA certs only)
- [x] 2.3 `CertificateLifecycleService::submitMetadata(secretId, userId, fields)` — owner-scoped + certificate-type-checked; upserts the metadata row; mirrors `not_after` into `expires_at` via `SecretService::setExpiry` (the rotation-expiry per-secret path — no ciphertext change, no `key_updated_at` reset; audits SECRET_EXPIRY_SET)
- [x] 2.4 `CertificateLifecycleService::reissueSuite(suiteId, userId, isAdmin)` — owner/admin-scoped; delegates to new public `CertificateAuthorityService::reissueSuiteCertificate` which wraps `resignPreservingPublicKey`; a key-changing result is rejected (RuntimeException → 409) and the existing cert kept; dispatches `certificate.reissued`
- [x] 2.5 `CertificateLifecycleService::renewalChecklist(secretId, userId)` — guided checklist for externally-issued stored certs; no private-CA signing call; dispatches `certificate.renewal_marked`
- [x] 2.6 `CertificateAuthorityService::getStatus` extended with `issued` counts (active user/application suites, stored certificate secrets, stored expiring ≤30d) via new `EncryptionSuiteMapper::countActiveByOwnerType` + `SecretMapper::countByTypeId`; the two extra mappers are nullable ctor params so existing constructions stay valid

## 3. Background job + notifications

- [x] 3.1 `ScanCertificateExpiryJob` (`TimedJob`, 86400s) — server-parses active USER suite cert `notAfter`; notifies at exact-day thresholds [30, 7, 1]. Dedup note: the daily cadence + exact-day match makes each threshold fire once per cert — the same storage-free dedup model as `ScanExpiringSecretsJob`. Application-owned suites are skipped (auto-re-signed; surfaced via CA health instead). Registered in `appinfo/info.xml`
- [x] 3.2 `certificate_expiring` subject in `NotificationService::SUBJECT_SETTING_MAP` (gated `notify_security`) + `DoriathNotifier` case
- [x] 3.3 `certificate.reissued` (`suiteId`) and `certificate.renewal_marked` ([]) in `AuditEventTypes` + whitelist (no DB migration)

## 4. Controllers + routes

- [x] 4.1 `CertificateController`: `inventory`, `submitMetadata`, `renewalChecklist`, `reissueSuite` — all `#[NoAdminRequired]` with per-object owner/admin guards enforced in the service (cross-owner → 403, regression-locked)
- [x] 4.2 CA health: `cACertificate#health` at `/api/v1/ca/health` (`#[AuthorizedAdminSetting]`) returns the extended status; routes registered under a commented "Certificate lifecycle" section

## 5. Dashboard + frontend

- [x] 5.1 `DashboardService::fetchSummary` admin-only `ca_health` card (status + root/intermediate expiry + issued counts; fail-soft null) via nullable `CertificateAuthorityService` ctor param
- [x] 5.2 `CertificateInventoryView` (`/certificates` manifest page + footer menu entry): three provenance-tagged sections with expiry badges; "Parse certificate" decrypts the stored secret in-browser and parses it with the new dependency-free `src/certificates/x509.js` DER parser (subject/issuer/serial/validity/fingerprint), then submits metadata — the PEM never leaves the browser
- [x] 5.3 Renewal: per-suite "Re-issue" action (private CA, same key pair); per-stored-cert "Renew…" checklist dialog (externally-issued honesty). CA-health issued-cert counts added to the existing admin `CaHealthSection` — the admin dashboard summary carries the same data via `ca_health`

## 6. Tests

- [x] 6.1 Unit: inventory tags `client_parsed`/`server_parsed` and the serialized inventory contains no `BEGIN CERTIFICATE`/`PRIVATE KEY`; server-parse vs client-submit split; cross-owner and wrong-type submissions rejected before any write; garbage dates rejected
- [x] 6.2 Unit: metadata submission mirrors `expires_at` through `SecretService::setExpiry` (the no-ciphertext-touch seam); re-issue owner guard; key-changing re-sign result rejected (kept cert); owner re-issue returns refreshed server-parsed row
- [x] 6.3 Unit: `ScanCertificateExpiryJob` notifies at an exact threshold, is silent off-threshold, and skips application-owned suites (`ScanCertificateExpiryJobTest`); x509.js client parser verified against a static fixture incl. never emitting private-key material (vitest)
- [x] 6.4 e2e: covered by deploy-time live verification on the dev instance (inventory + parse-on-decrypt + re-issue + CA health through the UI) — no separate Playwright spec committed

## Acceptance criteria

- Inventory lists all three sources tagged `client_parsed`/`server_parsed`, exposing no private key or ciphertext
- Stored-cert metadata is client-parsed; CA-issued metadata is server-parsed; client submissions for CA-issued certs are ignored (no endpoint accepts them); cross-owner submission rejected
- Submitting metadata sets `expires_at = notAfter` with ciphertext and `key_updated_at` unchanged
- Stored certs reuse the `rotation-expiry-policies` reminder job; the new job covers suite/app certs with no duplicate per cert+threshold
- Suite/app re-issue preserves the original public key; externally-issued certs get a checklist, never a private-CA signing call
- CA health with issued-cert counts shows on the admin dashboard, hidden from non-admins
- Re-issue and renewal-marked actions emit audit events with no PEM/key/value
