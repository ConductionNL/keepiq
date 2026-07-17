---
status: proposed
---

# Certificate Lifecycle

## Purpose

Give Doriath's existing private CA and `certificate` secret type the lifecycle tooling they lack: an inventory of every certificate the system can see, `notAfter`-driven expiry monitoring wired into the `rotation-expiry-policies` reminder machinery, guided renewal, and CA-health surfacing — all without decrypting stored certificate secrets (ADR-003).

## ADDED Requirements

### Requirement: Certificate inventory across all three sources

The system MUST provide an inventory that lists certificate-type secrets owned by the caller, the caller's CA-issued EncryptionSuite/application certificates, and (for administrators) the CA's own root/intermediate certificates. Each entry MUST carry a `metadataSource` distinguishing `client_parsed` (stored certificate secrets) from `server_parsed` (CA-issued certificates), and MUST NOT expose any private key or ciphertext.

#### Scenario: Owner lists their certificates
@e2e exclude Inventory aggregation across three data planes with source tagging is an API-shape assertion; covered by PHPUnit (CertificateLifecycleService inventory tests). The rendered list is captured in the certificate-lifecycle UI journeydoc.
- GIVEN a user owns two certificate-type secrets and one active EncryptionSuite
- WHEN they request the certificate inventory
- THEN the system MUST return all three entries, tagging the stored secrets `client_parsed` and the suite certificate `server_parsed`
- AND no entry MUST contain a private key or ciphertext

#### Scenario: CA certificates visible to admins only
@e2e exclude Admin-scope authorization on the CA rows is an attribute-enforced contract; covered by route-auth/semantic-auth gates + PHPUnit, not a DOM flow.
- GIVEN a non-administrator requests the inventory
- WHEN the response is assembled
- THEN it MUST NOT include the CA root or intermediate certificates

### Requirement: Metadata parsing is split by PEM readability

For a certificate-type secret the server MUST NEVER parse the PEM (it is ciphertext); the owner's browser MUST parse it client-side and submit the non-secret X.509 fields (subject, issuer, `notAfter`), which the system MUST persist as certificate metadata. For CA-issued certificates the system MUST parse the cleartext PEM server-side and MUST NOT accept client-submitted metadata for them.

#### Scenario: Client submits parsed metadata for a stored certificate
@e2e exclude Metadata persistence + owner scoping is an API contract; covered by PHPUnit (CertificateController metadata tests). The parse-on-decrypt UI is captured in the certificate-lifecycle journeydoc.
- GIVEN an owner has decrypted a certificate-type secret they own and parsed its PEM
- WHEN their browser submits the subject, issuer, and `notAfter`
- THEN the system MUST store a `doriath_certificate_metadata` row for that secret without decrypting anything server-side

#### Scenario: Server parses CA-issued certificate metadata
@e2e exclude Server-side `openssl_x509_parse` over cleartext PEM is not DOM-observable; covered by PHPUnit (server-parse tests).
- GIVEN a CA-issued EncryptionSuite certificate stored in cleartext
- WHEN its inventory entry is assembled
- THEN the system MUST derive subject, issuer, and `notAfter` server-side and MUST ignore any client-submitted metadata for it

#### Scenario: Cross-owner metadata submission rejected
@e2e exclude Owner-scoped authorization returning an error is an API contract; covered by PHPUnit (no-admin-idor guard test).
- GIVEN user A and a certificate-type secret owned by user B
- WHEN A submits parsed metadata for B's secret
- THEN the system MUST return an authorization error and store nothing

### Requirement: Expiry monitoring wired into the rotation-expiry reminder machinery

The system MUST use certificate `notAfter` as the expiry source: submitting metadata for a stored certificate secret MUST set that secret's `expires_at` to `notAfter` without altering the secret's `key` ciphertext or resetting `key_updated_at`, so the existing `rotation-expiry-policies` reminder job covers it. The system MUST additionally run a background job that reminds owners of approaching CA-issued suite/application certificate expiry using the same notification cadence.

#### Scenario: Stored certificate notAfter drives expires_at
@e2e exclude Verifying `expires_at` is set while ciphertext and `key_updated_at` are unchanged is a persistence assertion; covered by PHPUnit.
- GIVEN an owner submits parsed metadata whose `notAfter` is 20 days away
- WHEN the metadata is stored
- THEN the secret's `expires_at` MUST equal `notAfter`
- AND the secret's `key` ciphertext and `key_updated_at` MUST be unchanged

#### Scenario: Suite certificate approaching expiry reminds its owner
@e2e exclude Background-job behaviour over server-parsed suite certs; not DOM-testable; covered by PHPUnit (ScanCertificateExpiryJob threshold tests).
- GIVEN a CA-issued suite certificate within an approaching-expiry threshold and its owner's security notifications enabled
- WHEN the certificate-expiry scan job runs
- THEN the owner MUST receive a `certificate_expiring` notification identifying the certificate and its expiry date
- AND the job MUST NOT send a duplicate for the same certificate and threshold

### Requirement: Guided renewal by certificate origin

The system MUST re-issue a CA-issued suite or application certificate by re-signing its existing public key with the active CA intermediate, never generating a new key pair. For an externally-issued stored certificate the system MUST NOT attempt automated renewal; it MUST return a renewal checklist and support a replace-value flow that rewrites the secret's `key` and accepts fresh parsed metadata.

#### Scenario: Suite certificate re-issued preserving its public key
@e2e exclude Re-sign preserving the modulus is a crypto-API assertion over server state; covered by PHPUnit (reissue tests asserting the new cert carries the original public key).
- GIVEN an owner requests re-issue of their CA-issued suite certificate
- WHEN the re-issue completes
- THEN the new certificate MUST carry the suite's original public key
- AND the system MUST reject any result whose public key differs, keeping the existing certificate

#### Scenario: Externally-issued certificate gets a checklist, not automation
@e2e exclude Checklist assembly + absence of a CA-signing call is an API/behaviour contract; covered by PHPUnit.
- GIVEN an owner requests renewal of an externally-issued stored certificate secret
- WHEN the request is handled
- THEN the system MUST return a renewal checklist and MUST NOT sign anything with the private CA
- AND replacing the value MUST rewrite the secret's `key` and accept re-submitted metadata

### Requirement: CA health on the admin dashboard

The system MUST surface CA health to administrators — root and intermediate expiry plus issued-certificate counts — on the Doriath admin dashboard. Non-administrators MUST NOT see CA-health data.

#### Scenario: Admin sees CA health with issued-cert counts
@e2e exclude CA-health card is rendered inside the admin dashboard; the admin-facing display is covered by the dashboard/admin-settings spec scenarios and PHPUnit for the counts.
- GIVEN an administrator opens the Doriath dashboard
- WHEN the CA-health card is assembled
- THEN it MUST show root expiry, intermediate expiry/status, and counts of issued certificates

### Requirement: Certificate lifecycle actions are audited without secret material

The system MUST emit audit events for re-issue and renewal-marked actions using the existing string-typed audit whitelist, carrying no PEM, private key, or secret value.

#### Scenario: Re-issue is audited without certificate material
@e2e exclude Audit-dispatch payload assertion (event type recorded, no key material); covered by PHPUnit (AuditService whitelist tests).
- WHEN an owner re-issues a suite certificate
- THEN a `certificate.reissued` audit event MUST be recorded with no key, PEM, value, or ciphertext field

## User Stories

- As a user, I want one place that lists all my certificates with their expiry dates so I can see what is about to lapse
- As a user, I want a reminder before a stored certificate expires so I can renew it before an outage
- As a user, I want to re-issue my Doriath-issued certificate in one click without regenerating my key pair
- As a user, I want a clear checklist for renewing a certificate Doriath did not issue, since it cannot do it for me
- As an administrator, I want CA health and issued-certificate counts on the dashboard so I can spot PKI problems early

## Acceptance Criteria

- [ ] Inventory lists stored certificate secrets, suite/app certs, and (admin) CA certs, each tagged `client_parsed` or `server_parsed`, with no private key or ciphertext exposed
- [ ] Stored certificate metadata is parsed client-side and submitted; CA-issued metadata is parsed server-side and client submissions for it are ignored
- [ ] Cross-owner metadata submission is rejected
- [ ] Submitting stored-cert metadata sets `expires_at = notAfter` without changing `key` ciphertext or `key_updated_at`
- [ ] Stored certs ride the existing `rotation-expiry-policies` reminder job; a new job reminds on CA-issued suite/app cert expiry with no duplicate per cert+threshold
- [ ] Suite/app re-issue preserves the original public key and rejects any key-changing result
- [ ] Externally-issued certs get a checklist + replace-value flow, never a private-CA signing call
- [ ] CA health (root/intermediate expiry + issued-cert counts) shows on the admin dashboard, hidden from non-admins
- [ ] Re-issue and renewal-marked actions emit audit events carrying no PEM, key, or value

## Notes

- Honest boundary: the server cannot parse or verify stored certificate PEM (ADR-003); stored-cert `notAfter` is client-submitted and trusted (blast radius = that owner's own reminder timing).
- Reuses: `expires_at` + `ScanExpiringSecretsJob` + `notify_security` from `rotation-expiry-policies`; the preserve-public-key re-sign path from `encryption-suites` (`CertificateAuthorityService::resignPreservingPublicKey`); the string-typed audit whitelist from `secret-audit-trail`; the dashboard counter pattern from `application-mgmt`.
- Distinguishes the CA (infrastructure: root/intermediate) from certificate secrets (stored external cert material of the `certificate` system type).
- Related ADRs: ADR-001 (own tables — imperative, no OpenRegister), ADR-003 (zero-knowledge, no server-side decryption of stored secrets).
