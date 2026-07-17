# Tasks: Certificate Lifecycle

## 1. Data layer

- [ ] 1.1 Migration: `doriath_certificate_metadata` table (`id`, `secret_id` FK+unique, `owner_id`, `subject` text, `issuer` text, `serial`, `fingerprint_sha256`, `not_before` nullable, `not_after` nullable, `parsed_at`)
- [ ] 1.2 `CertificateMetadata` entity + `CertificateMetadataMapper` (standard `QBMapper`, matching `SecretMapper`); `findBySecretId`, `findByOwner`

## 2. Service layer

- [ ] 2.1 `CertificateLifecycleService::inventory(userId, isAdmin)` — merge stored certificate-type secrets, CA-issued suite/app certs, and (admin) CA certs; tag each `metadataSource`; never emit private key/ciphertext
- [ ] 2.2 `CertificateLifecycleService::parseCaCertificate(pem)` — server-side `openssl_x509_parse` of cleartext PEM (suite/app/CA certs only)
- [ ] 2.3 `CertificateLifecycleService::submitMetadata(secretId, userId, fields)` — owner-scoped; upsert metadata row; set the secret's `expires_at = not_after` via the `rotation-expiry-policies` per-secret expiry path (no ciphertext change, no `key_updated_at` reset)
- [ ] 2.4 `CertificateLifecycleService::reissueSuite(suiteId, userId)` — re-sign the existing public key via `CertificateAuthorityService::resignPreservingPublicKey`; reject any key-changing result; dispatch `certificate.reissued`
- [ ] 2.5 `CertificateLifecycleService::renewalChecklist(secretId, userId)` — return guided checklist for externally-issued stored certs; no private-CA signing call
- [ ] 2.6 Extend `CertificateAuthorityService::getStatus` with issued-cert counts (active suites, applications, expiring stored certs)

## 3. Background job + notifications

- [ ] 3.1 `ScanCertificateExpiryJob` (`TimedJob`, 86400s) — server-parse active suite/app cert `notAfter`; dispatch `certificate_expiring` at approaching thresholds, dedup per cert+threshold; register in `appinfo/info.xml` `<background-jobs>`
- [ ] 3.2 Add `certificate_expiring` subject to `NotificationService::SUBJECT_SETTING_MAP` (gated on `notify_security`) + `DoriathNotifier` case
- [ ] 3.3 Add `certificate.reissued` and `certificate.renewal_marked` to `AuditEventTypes` + whitelist entries (no DB migration)

## 4. Controllers + routes

- [ ] 4.1 `CertificateController`: `inventory` (`#[NoAdminRequired]`), `submitMetadata` (owner-scoped), `renewalChecklist` (owner-scoped), `reissueSuite` (owner/admin-scoped) — each with explicit auth attribute + per-object guard
- [ ] 4.2 Extend the CA-status endpoint (`#[AuthorizedAdminSetting]`) with issued-cert counts; register all routes in `appinfo/routes.php` under a commented "Certificate lifecycle" section

## 5. Dashboard + frontend

- [ ] 5.1 Extend `DashboardService::fetchSummary` with an admin-only CA-health card (root/intermediate expiry + issued-cert counts)
- [ ] 5.2 Certificate inventory view: list with subject/issuer/expiry badges; parse-on-decrypt submits metadata client-side (WebCrypto path, same as secret reveal)
- [ ] 5.3 Renewal wizard: private-CA re-issue for suite/app certs; checklist + replace-value for externally-issued stored certs; CA-health card component on the admin dashboard

## 6. Tests

- [ ] 6.1 Unit: inventory tags sources correctly and never leaks key/ciphertext; server-parse vs client-submit split; cross-owner metadata submission rejected
- [ ] 6.2 Unit: metadata submission sets `expires_at` without changing ciphertext/`key_updated_at`; re-issue preserves the original public key and rejects key-changing results
- [ ] 6.3 Unit: `ScanCertificateExpiryJob` reminds only within thresholds with no duplicate per cert+threshold; audit events carry no PEM/key/value
- [ ] 6.4 e2e (Playwright): owner opens inventory, decrypts a stored cert (metadata + expiry badge appear), re-issues a suite cert, and sees the admin CA-health card

## Acceptance criteria

- Inventory lists all three sources tagged `client_parsed`/`server_parsed`, exposing no private key or ciphertext
- Stored-cert metadata is client-parsed; CA-issued metadata is server-parsed; client submissions for CA-issued certs are ignored; cross-owner submission rejected
- Submitting metadata sets `expires_at = notAfter` with ciphertext and `key_updated_at` unchanged
- Stored certs reuse the `rotation-expiry-policies` reminder job; the new job covers suite/app certs with no duplicate per cert+threshold
- Suite/app re-issue preserves the original public key; externally-issued certs get a checklist, never a private-CA signing call
- CA health with issued-cert counts shows on the admin dashboard, hidden from non-admins
- Re-issue and renewal-marked actions emit audit events with no PEM/key/value
