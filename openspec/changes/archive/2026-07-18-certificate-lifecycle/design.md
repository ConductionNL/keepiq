# Design: Certificate Lifecycle

## Context

Doriath already owns a private CA and issues certificates on two very different data planes, and this distinction drives the whole design:

- **CA-issued certificates** (root/intermediate in `doriath_ca_certificates`, user/application suite certs in `doriath_enc_suites.certificate`) are stored as **cleartext PEM** — the server signed them and holds them in the clear. The server can `openssl_x509_parse` them at will. The CA machinery (`CertificateAuthorityService`, `CheckRootCertificateExpiry`, `RenewIntermediateCertificate`) already manages the root/intermediate; suite certs are auto-re-signed on intermediate renewal (`resignAllActiveSuites` `:595`).
- **Certificate-type secrets** (`certificate` is a system secret type, `SeedSecretTypes.php:66`) store an externally-issued certificate's PEM in the **encrypted `key` blob**. Per ADR-003 the server never decrypts it, so it can never know the certificate's `notAfter`. Only the owner's browser, after unlocking, can parse it.

Wave-1 `rotation-expiry-policies` supplies the reminder loop this change reuses: a per-secret `expires_at` (set without touching ciphertext or `key_updated_at`), a `ScanExpiringSecretsJob` that notifies owners crossing approaching/overdue thresholds, and the `notify_security`-gated `NotificationService` path.

## Goals / Non-Goals

**Goals:**
- One inventory that lists every certificate Doriath can see — stored certificate secrets, suite/app certs, CA certs — each with subject / issuer / `notAfter`.
- Expiry monitoring driven by certificate `notAfter`, reusing the `rotation-expiry-policies` reminder machinery rather than a parallel one.
- Guided renewal: re-issue suite/app certs from the private CA; a checklist + replace-value flow for externally-issued stored certs.
- CA health (root/intermediate expiry, issued-cert counts) on the admin dashboard.
- Zero server-side decryption of stored certificate secrets (ADR-003 preserved).

**Non-Goals:**
- External-CA integration / ACME issuance (Infisical's paid PKI surface) — out of scope.
- Certificate revocation lists (CRL/OCSP) for stored external certs.
- Re-issuing externally-issued certificates (Doriath has no signing relationship with a third-party CA — the honest answer is a checklist, not automation).

## Data Model

Own tables per **ADR-001** (no OpenRegister).

### `doriath_certificate_metadata`
Client-parsed, **non-secret** X.509 display metadata for an encrypted certificate-type secret. Populated only by the owner's browser after it decrypts and parses the PEM; never derived server-side.

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | PK |
| `secret_id` | string FK → `doriath_secrets.id` | Unique — one metadata row per certificate secret |
| `owner_id` | string | Denormalized owner (NC user id) for owner-scoped queries |
| `subject` | text | X.509 subject DN (non-secret metadata, analogous to `name`/`url`) |
| `issuer` | text | X.509 issuer DN |
| `serial` | string | Certificate serial |
| `fingerprint_sha256` | string | `sha256:`-prefixed fingerprint |
| `not_before` | datetime nullable | Parsed validity start |
| `not_after` | datetime nullable | Parsed validity end — mirrored to the secret's `expires_at` |
| `parsed_at` | datetime | When the client last submitted |

No new column on `doriath_secrets`: `expires_at` (from `rotation-expiry-policies`) is the expiry source of truth; CA-issued certs need no table (parsed on the fly from cleartext PEM).

## Decisions

### D1: Metadata parsing is split by PEM readability (the core constraint)
Stored certificate secrets are parsed **client-side** (the server holds only ciphertext) and the browser POSTs the parsed non-secret fields. CA-issued suite/app/CA certs are parsed **server-side** with `openssl_x509_parse` on the cleartext PEM Doriath already stores. The inventory response merges both, tagging each row with its `metadataSource` (`client_parsed` | `server_parsed`) so the UI is honest about provenance.

### D2: Expiry rides `rotation-expiry-policies`, not a new reminder loop
For stored certificate secrets the client-submitted `not_after` is written to the secret's `expires_at` via the existing per-secret expiry mechanism (no ciphertext change, no `key_updated_at` reset). The existing `ScanExpiringSecretsJob` therefore already reminds on them. For CA-issued suite/app certs — which are `doriath_enc_suites` rows, not `doriath_secrets`, so that job never sees them — a thin new `ScanCertificateExpiryJob` parses `notAfter` server-side and dispatches the same `certificate_expiring` reminder subject on the same cadence. One reminder concept, two object domains.

### D3: Suite/app renewal re-signs the existing public key — never a new key pair
Re-issue reuses `CertificateAuthorityService::resignPreservingPublicKey` (`:691`), which mints a new certificate carrying the suite's **original** public key and rejects any result whose modulus differs (the public-only-key footgun guard, `:746`). Minting a fresh key pair would make every value encrypted under the new cert undecryptable with the owner's wrapped private key — the read-after-write decrypt failure. Zero-knowledge invariant preserved.

### D4: Externally-issued stored certs get a checklist, not automation
Doriath has no signing relationship with the third-party CA that issued a stored certificate, so it cannot renew it. The renewal flow returns a guided **checklist** (obtain a new cert from the issuing CA, verify chain, note new `notAfter`) and then a **replace-value** step — a normal secret update that rewrites the `key` ciphertext client-side; the browser then re-parses and re-submits `doriath_certificate_metadata` + `expires_at`. Honest boundary, stated in docs.

### D5: Trusting client-submitted `notAfter`
The client already holds the plaintext certificate; a wrong `not_after` only mis-times a reminder to that same owner and reaches no other tenant. The server validates the submission is a parseable date, owner-scoped to a secret the caller owns, and of the `certificate` type — but does not (cannot) cryptographically verify it against the ciphertext. Accepted trade-off, documented.

### D6: CA-health surfacing extends existing seams
`CertificateAuthorityService::getStatus` (`:446`) is extended with issued-cert counts (active suites, applications, stored certificate secrets nearing expiry); `DashboardService::fetchSummary` (`:116`) gains a CA-health card for admins only (mirrors the pending-applications counter pattern).

## Endpoints

All `CertificateController` methods declare an explicit NC auth attribute; owner-scoped methods guard per-object (no IDOR).

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | `/api/v1/certificates/inventory` | `#[NoAdminRequired]` | Owner's cert-type secrets + suite/app certs; admin also sees CA certs + all issued |
| PUT | `/api/v1/certificates/{secretId}/metadata` | `#[NoAdminRequired]` | Owner submits client-parsed metadata; sets `expires_at = not_after` |
| POST | `/api/v1/certificates/{secretId}/renewal-checklist` | `#[NoAdminRequired]` | Guided checklist for an externally-issued stored cert |
| POST | `/api/v1/certificates/suites/{suiteId}/reissue` | `#[NoAdminRequired]` | Re-sign a suite/app cert's existing public key from the private CA |
| GET | `/api/v1/ca/health` | `#[AuthorizedAdminSetting]` | Root/intermediate expiry + issued-cert counts |

## Background jobs

- New `ScanCertificateExpiryJob` (extends `TimedJob`, 86400s like the CA jobs) — parses `notAfter` of active suite/application certificates server-side, dispatches `certificate_expiring` at approaching thresholds via `NotificationService` (gated `notify_security`), dedup per cert+threshold. Registered in `info.xml` `<background-jobs>` (`:69`).
- Stored certificate secrets ride the existing `rotation-expiry-policies` `ScanExpiringSecretsJob` through `expires_at`.

## Audit

New string event types in `AuditEventTypes` (no migration): `certificate.reissued` (actor = owner/admin, metadata `{ suiteId }`), `certificate.renewal_marked` (actor = owner, metadata `{}`). No PEM, key, or secret value ever recorded — the whitelist forbids it structurally.

## Declarative-vs-imperative decision

Imperative PHP services and migrations per **ADR-001** — Doriath owns its tables and logic; no OpenRegister schema/declarative object model is involved.

## Decisions made under uncertainty

- **Non-secret X.509 metadata is safe to persist server-side.** We treat subject/issuer/`notAfter`/fingerprint as non-secret display metadata (like the already-plaintext `name`/`url`), not as key material. Assumption: the private key is the only secret; the certificate's public fields are not. If an operator considers subject/issuer sensitive, the table is owner-scoped and never shared.
- **Reuse over rebuild for the reminder loop.** We assume `rotation-expiry-policies`' `expires_at` + `ScanExpiringSecretsJob` is the right reminder home for stored certs, and only add a sibling job for the `enc_suites` domain it structurally can't reach. If that change's field names shift, this change re-points to them (declared `depends_on`).
- **Client-submitted `notAfter` is trusted (D5).** No cryptographic server-side verification is possible without decryption; blast radius is one owner's own reminder timing.
- **Externally-issued certs are not auto-renewable (D4).** We assume the honest checklist + replace-value is preferable to pretending Doriath can renew a third-party cert.
- **Suite-cert expiry monitoring is additive, not a replacement** for the existing auto-re-sign on intermediate renewal — the new job only *notifies*; it never silently mints keys.
