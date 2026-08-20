---
kind: code
depends_on: [rotation-expiry-policies]
---

# Proposal: Certificate lifecycle management over the private CA and certificate secrets

## Why

Doriath already runs a private Certificate Authority (root + intermediate, `lib/Service/CertificateAuthorityService.php:40` — bootstrap `:95`, `signPublicKey` `:217`, `signCsr` `:283`, `renewIntermediate` `:321`, `renewRoot` `:370`, preserve-public-key re-sign `:691`) and ships `certificate` as one of its 7 system secret types (`lib/Repair/SeedSecretTypes.php:66`, a UI hint whose PEM material lives in the encrypted `key` blob like every other type). But it has **zero lifecycle tooling** around either surface: the only automated certificate machinery is `lib/BackgroundJob/CheckRootCertificateExpiry.php:35` (root notify at 90/30/7 days) and `lib/BackgroundJob/RenewIntermediateCertificate.php:35` (intermediate auto-renew). There is no inventory of certificate-type secrets, no expiry monitoring for stored certificates, no renewal flow, and the admin CA panel (`lib/Controller/CACertificateController.php:63` → `getStatus`) surfaces only root/intermediate dates — not issued-cert counts.

Certificates are credentials with the most predictable failure mode: expiry. Infisical expanded into private PKI (internal CA + external CA integration) as a growth surface precisely because expired-certificate outages are a recurring public-sector incident class, and BIO2/NIS2 machine-credential hygiene extends to certificates. Doriath owns the private CA and the certificate secret type but leaves the expiry outage — the exact thing a secrets manager should prevent — unmanaged. Wave-1 `rotation-expiry-policies` just built the expiry/reminder machinery (`expires_at` per-secret, `ScanExpiringSecretsJob`, `notify_security`-gated reminders); this change wires certificate `notAfter` into it rather than duplicating it.

## What Changes

- Add a **certificate inventory** aggregating three sources: certificate-type secrets (`doriath_secrets` where the type is the system `certificate` type), CA-issued EncryptionSuite/application certificates (`doriath_enc_suites.certificate`), and the CA's own root/intermediate certificates (`doriath_ca_certificates`).
- **Metadata parsing is split by who can read the PEM.** For encrypted stored certificate secrets the server can NEVER parse the PEM (it is ciphertext in `key`, ADR-003) — subject/issuer/`notAfter` are parsed **client-side** after the owner decrypts, then submitted as non-secret X.509 display metadata. For CA-issued suite/application/CA certificates the PEM is stored in cleartext and the server parses it **server-side** via `openssl_x509_parse` (the server already knows this material).
- **Wire expiry monitoring into `rotation-expiry-policies`' reminder machinery** using certificate `notAfter` as the expiry source: for stored certificate secrets the client-submitted `notAfter` sets the secret's `expires_at` (reusing the per-secret expiry endpoint — no ciphertext change, no reset of `key_updated_at`), so the existing `ScanExpiringSecretsJob` already covers them; a thin new `ScanCertificateExpiryJob` covers the CA-issued suite/application certificates (a different object domain than `doriath_secrets`, computed server-side) with the same notification cadence.
- Add **guided renewal flows**: (a) suite/application certificates re-issue from the private CA by re-signing the existing public key with the active intermediate — reusing the preserve-public-key path (`CertificateAuthorityService::resignPreservingPublicKey` `:691`), never minting a new key pair; (b) externally-issued stored certificates cannot be renewed by Doriath (no CA relationship), so they get a renewal **checklist** plus a **replace-value** flow (a normal secret update that rewrites `key` and re-submits parsed metadata).
- **Surface CA health on the admin dashboard**: root expiry, intermediate expiry/status, and issued-certificate counts (extending `DashboardService::fetchSummary` `:116` and the CA status endpoint).
- Add **audit events** for certificate lifecycle actions using the existing string-typed, migration-free whitelist (`lib/Event/Audit/AuditEventTypes.php`).

## Capabilities

### New Capabilities
- `certificate-lifecycle`: a certificate inventory with metadata parsed client-side for encrypted stored certificates and server-side for CA-issued certificates, `notAfter`-driven expiry monitoring wired into the `rotation-expiry-policies` reminder machinery, guided renewal (private-CA re-issue for suite/app certs; checklist + replace-value for externally-issued stored certs), and CA-health surfacing on the admin dashboard.

### Modified Capabilities
- _(none — this change composes with `rotation-expiry-policies` (reuses its `expires_at`/reminder loop) and `encryption-suites` (reuses CA re-sign) by reference; it adds no MODIFIED requirement to their existing scenarios.)_

## Impact

- **New tables** (own DB per ADR-001): `doriath_certificate_metadata` (client-parsed non-secret X.509 display fields for stored certificate secrets: `secret_id`, `subject`, `issuer`, `serial`, `fingerprint_sha256`, `not_before`, `not_after`, `parsed_at`). No new column is added to `doriath_secrets` — `expires_at` from `rotation-expiry-policies` is reused. No OpenRegister.
- **Services**: new `CertificateLifecycleService` (inventory aggregation, server-side parse of CA-issued PEM, metadata persistence, re-issue orchestration); extends `CertificateAuthorityService` (issued-cert counts), `DashboardService` (CA-health card), `NotificationService` (new `certificate_expiring` subject, gated on `notify_security`).
- **Background jobs**: new `ScanCertificateExpiryJob` registered in `appinfo/info.xml` `<background-jobs>` (`info.xml:69`) alongside the CA/audit/emergency jobs; stored-cert expiry rides the existing `ScanExpiringSecretsJob`.
- **Routes/controllers**: new `CertificateController` — inventory (`#[NoAdminRequired]`, owner-scoped), submit-parsed-metadata (owner-scoped), renewal checklist (owner-scoped), suite/app re-issue (owner/admin-scoped); CA-health extension is `#[AuthorizedAdminSetting]`.
- **Frontend**: certificate inventory view with parsed metadata + expiry badges; renewal wizard; CA-health card on the admin dashboard.
- **Audit**: new event types `certificate.reissued`, `certificate.renewal_marked` added to `AuditEventTypes` (no DB migration — string types).
- **OpenConnector**: none directly; the machine secret-store API is untouched.
