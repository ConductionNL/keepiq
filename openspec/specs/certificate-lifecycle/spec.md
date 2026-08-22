# Certificate Lifecycle Specification

**Status**: done

**OpenSpec changes:**
- [certificate-lifecycle](../../changes/archive/2026-07-18-certificate-lifecycle/) — inventory, notAfter-driven expiry monitoring (reusing rotation-expiry-policies), guided renewal, CA-health surfacing

## Purpose

Keepiq already runs a private Certificate Authority (`lib/Service/CertificateAuthorityService.php`) and ships `certificate` as a system secret type (`lib/Repair/SeedSecretTypes.php:66`), but has no lifecycle tooling around either — the only automated machinery is the root-expiry notify job (`lib/BackgroundJob/CheckRootCertificateExpiry.php`) and intermediate auto-renew (`lib/BackgroundJob/RenewIntermediateCertificate.php`). This feature adds a certificate inventory, `notAfter`-driven expiry monitoring wired into the wave-1 `rotation-expiry-policies` reminder loop, guided renewal (private-CA re-issue for suite/app certs; a checklist + replace-value flow for externally-issued stored certs), and CA-health surfacing on the admin dashboard — parsing certificate metadata client-side for encrypted stored certificates and server-side only for the CA's own issued certificates, preserving the zero-knowledge model (ADR-003).

## Requirements

### Requirement: Certificate inventory across all three sources
The system MUST provide an inventory of certificate-type secrets, CA-issued suite/application certificates, and (for admins) the CA's own certificates, tagging each entry's metadata provenance and never exposing a private key or ciphertext.

#### Scenario: Owner lists their certificates
- GIVEN a user owns certificate-type secrets and an active EncryptionSuite
- WHEN they request the inventory
- THEN the system MUST return the entries tagged `client_parsed` (stored secrets) or `server_parsed` (CA-issued), with no private key or ciphertext

### Requirement: Metadata parsing split by PEM readability
The system MUST parse stored certificate-secret metadata client-side (the server holds only ciphertext) and CA-issued certificate metadata server-side, rejecting client submissions for CA-issued certificates.

#### Scenario: Client submits parsed metadata for a stored certificate
- GIVEN an owner has decrypted and parsed a certificate-type secret they own
- WHEN their browser submits subject, issuer, and notAfter
- THEN the system MUST persist the metadata without decrypting anything server-side

### Requirement: Expiry monitoring reuses the rotation-expiry reminder machinery
The system MUST set a stored certificate secret's `expires_at` to its `notAfter` (no ciphertext change) so the existing reminder job covers it, and MUST additionally remind owners of approaching CA-issued suite/application certificate expiry on the same cadence.

#### Scenario: Suite certificate approaching expiry reminds its owner
- GIVEN a CA-issued suite certificate within an approaching-expiry threshold and its owner's security notifications enabled
- WHEN the certificate-expiry scan job runs
- THEN the owner MUST receive a `certificate_expiring` notification with no duplicate per certificate and threshold

### Requirement: Guided renewal by certificate origin
The system MUST re-issue CA-issued suite/application certificates by re-signing the existing public key (never a new key pair), and MUST give externally-issued stored certificates a renewal checklist plus a replace-value flow instead of automation.

#### Scenario: Suite certificate re-issued preserving its public key
- GIVEN an owner requests re-issue of their CA-issued suite certificate
- WHEN it completes
- THEN the new certificate MUST carry the suite's original public key, and any key-changing result MUST be rejected

### Requirement: CA health on the admin dashboard
The system MUST surface root/intermediate expiry and issued-certificate counts to administrators on the dashboard, hidden from non-admins.

#### Scenario: Admin sees CA health with issued-cert counts
- GIVEN an administrator opens the dashboard
- WHEN the CA-health card is assembled
- THEN it MUST show root expiry, intermediate expiry/status, and issued-certificate counts

## User Stories

- As a user, I want one place listing all my certificates with expiry dates
- As a user, I want a reminder before a stored certificate expires
- As a user, I want one-click re-issue of my Keepiq-issued certificate without regenerating my key pair
- As a user, I want a checklist for renewing a certificate Keepiq did not issue
- As an administrator, I want CA health and issued-cert counts on the dashboard

## Acceptance Criteria

- [ ] Inventory lists all three sources tagged by metadata provenance, exposing no private key/ciphertext
- [ ] Stored-cert metadata is client-parsed; CA-issued is server-parsed; client submissions for CA-issued are ignored; cross-owner rejected
- [ ] Submitting metadata sets `expires_at = notAfter` without changing ciphertext or `key_updated_at`
- [ ] Stored certs reuse the rotation-expiry reminder job; a new job covers suite/app certs with no duplicate per cert+threshold
- [ ] Suite/app re-issue preserves the original public key; externally-issued certs get a checklist, not automation
- [ ] CA health with issued-cert counts shows on the admin dashboard, hidden from non-admins
- [ ] Re-issue and renewal-marked actions emit audit events carrying no PEM/key/value

## Notes

- Depends on `rotation-expiry-policies` (reuses `expires_at`, `ScanExpiringSecretsJob`, `notify_security`); reuses the preserve-public-key re-sign from `encryption-suites` and the string audit whitelist from `secret-audit-trail`.
- Distinguishes the CA (infrastructure) from certificate secrets (stored external cert material of the `certificate` system type).
- Related ADRs: ADR-001 (own tables — imperative, no OpenRegister), ADR-003 (zero-knowledge, no server-side decryption).
