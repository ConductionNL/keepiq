# Doriath — Feature Analysis & Product Strategy

## Executive Summary

There is **no production-ready Nextcloud-native encrypted vault with application secret management**. The existing "Passwords" app provides basic password management but lacks enterprise-grade encryption (no PKI, no private CA, no application secrets). The broader market splits into consumer password managers (Bitwarden, 1Password) and infrastructure secret engines (HashiCorp Vault, AWS Secrets Manager) — no tool bridges both worlds on a self-hosted collaboration platform.

**Key insight:** Nextcloud is already the collaboration hub — users, groups, files, notifications, and search are already there. A Nextcloud-native vault orchestrates these capabilities for secret management: share secrets with Nextcloud users/groups, notify via the bell icon, search from the unified search bar, and manage application credentials alongside your team workspace.

## 1. Competitive Landscape

### Nextcloud App Store

| Name | Status | Downloads | Last Updated | Key Features | Gaps |
|------|--------|-----------|-------------|--------------|------|
| **Passwords** (Marius David Wieschollek) | Active, mature | 500K+ | 2024 | Password CRUD, folder organization, tags, password generator, sharing (user/link), browser extensions, API, client-side encryption options | No private CA, no application secrets, no CSR-based onboarding, no write-without-read, no enterprise key management |
| **Secrets** (various) | Early/experimental | Low | — | Basic encrypted notes | Not a vault; minimal functionality |

**Finding:** The "Passwords" app is the main Nextcloud competitor. It is mature and widely adopted but architecturally simpler — it uses server-side encryption (SSE) or client-side encryption (CSE) without PKI infrastructure. It cannot manage application secrets, has no write-without-read capability, and lacks a Certificate Authority for enterprise key management.

### Self-Hosted Open Source

| Name | GitHub Stars | Positioning | Key Features | Weaknesses |
|------|-------------|-------------|--------------|------------|
| **Bitwarden** (bitwarden/server) | 16K+ | Full-featured password manager | Web vault, browser extensions, mobile apps, CLI, org vaults, SSO, emergency access, FIDO2, password health reports, Send (ephemeral sharing) | Heavy (.NET stack), requires multiple containers, no Nextcloud integration |
| **Vaultwarden** | 42K+ | Lightweight Bitwarden-compatible server (Rust) | Same client ecosystem as Bitwarden, single binary, low resource usage, org vaults, Send | No native Nextcloud integration, no application secret management, no private CA |
| **Passbolt** | 4K+ | Team password manager (PHP/CakePHP) | End-to-end GPG encryption, team sharing, RBAC, LDAP/AD, audit logs, folders, tags, mobile apps, API | GPG-based (not PKI/CA), no application secrets, no write-without-read, complex setup |
| **KeePass / KeePassXC** | — / 19K+ | Offline password database | KDBX format, strong encryption (AES-256/ChaCha20), browser integration, TOTP, auto-type, plugins | Offline-only, no server, no sharing, no team features, no API |
| **Psono** | 1.5K+ | Enterprise team password manager | E2E encryption (Curve25519 + Salsa20), team sharing, LDAP, file encryption, API keys, emergency codes | Python/Django stack, smaller community, no Nextcloud integration |
| **Teampass** | 1.6K+ | Collaborative password manager (PHP) | Team folders, roles, export, API, LDAP, 2FA | Dated UI, PHP but not Nextcloud-integrated, simpler encryption model |
| **Padloc** | 2.5K+ | Modern cross-platform password manager | Clean UI, E2E encryption (SRP + AES), vaults, sharing, org management | TypeScript/Electron, smaller ecosystem, no application secrets |

### Enterprise SaaS

| Name | Price/user/mo | Target Audience | Key Features | Why Not |
|------|--------------|-----------------|--------------|---------|
| **1Password** | $8–20 | Teams, enterprises | Vaults, sharing, watchtower (breach detection), SSO, SCIM, CLI, secrets automation, service accounts | SaaS-only, US jurisdiction, expensive at scale, data sovereignty |
| **LastPass** | $4–7 | Consumer, small teams | Password vault, sharing, dark web monitoring, SSO, admin console | Repeated security breaches, SaaS-only, trust issues |
| **HashiCorp Vault** | Free OSS / $1.58/hr (HCP) | DevOps, platform teams | Dynamic secrets, secret engines, PKI engine, transit encryption, audit logging, policies, namespaces | Infrastructure tool (not a password manager), complex, no end-user UI |
| **AWS Secrets Manager** | $0.40/secret/mo | AWS-native workloads | Secret rotation, RDS integration, cross-account sharing, CloudFormation | Cloud-locked, no user-facing UI, no sharing, not self-hosted |
| **Proton Pass** | Free–$4 | Privacy-focused consumers | E2E encrypted, zero-knowledge, aliasing, Proton ecosystem integration | Consumer-focused, no team features, no application secrets, SaaS-only |
| **Keeper** | $5–8 | Enterprises | Zero-knowledge, secrets manager, connection manager, PAM, compliance reports | SaaS-only, expensive, complex licensing |

### Dutch Government

| Name | Type | Status | Key Features |
|------|------|--------|--------------|
| **Haven (NLnet Labs)** | Research / PKI toolkit | Active development | RPKI tooling, not a secrets manager |
| **No direct equivalent** | — | — | Dutch government has no mandated secrets management standard. Municipalities typically use commercial tools (1Password, Azure Key Vault) or rely on OS-level key management |

**Finding:** There is no Dutch government standard or Common Ground component for secrets management. This is an opportunity — Doriath could become the reference implementation for sovereign secrets management in the Dutch public sector.

## 2. Feature Matrix

### Core Secret Management

| Feature | Tier | Justification |
|---------|------|---------------|
| Secret CRUD (name, key, login, url, additional fields) | **MVP** | Core entity — see [secrets spec](../openspec/specs/secrets/spec.md) |
| Secret types (login, api_key, ssh_key, certificate, note, database) | **MVP** | UI hint system with 6 system types |
| Custom secret types (user-scoped and admin global) | **MVP** | Extensibility for org-specific secret categories |
| Folder organization (tree hierarchy per user) | **MVP** | Essential organizational pattern |
| Folder CRUD (create, rename, move, delete with cascade options) | **MVP** | Full folder management |
| Secret list with search, sort, pagination | **MVP** | Core navigation |
| Copy-to-clipboard on secret list items | **MVP** | Most-used action — copy password without opening detail (Bitwarden, 1Password pattern) |
| Show/hide toggle on password fields | **MVP** | Standard pattern — passwords hidden by default with eye icon |
| Favicon/icon next to secrets by URL | **MVP** | Visual identification of which service a secret belongs to (1Password, Bitwarden) |
| Secret detail view with type-specific field presentation | **MVP** | Critical UX pattern |
| Fuzzy search by name and URL (Levenshtein tolerance) | **MVP** | Typo-tolerant search |
| Nextcloud unified search integration (IProvider) | **MVP** | Find secrets from Ctrl+F without opening Doriath |
| Deep-link from search results via lock screen | **MVP** | Seamless search → vault flow |
| Bulk secret operations (delete, move folder) | **V1** | Efficiency for large vaults |
| Secret import (CSV, Bitwarden JSON, KeePass XML) | **V1** | Migration from other tools |
| Secret export (encrypted backup, CSV) | **V1** | Data portability |
| Favorite/pinned secrets | **V1** | Quick access to frequently used secrets |
| Recently accessed secrets | **V1** | Convenience pattern from all major vaults |
| Password health scoring per secret | **V1** | Flag weak, reused, or old passwords (Bitwarden Reports, 1Password Watchtower) |
| Secret strength indicator in list view | **V1** | Color-coded strength badge next to each secret (Passbolt, Bitwarden) |
| Vault search with keyboard shortcut (Ctrl+K) | **V1** | Power-user quick access (1Password pattern) |
| Dark mode support | **V1** | User preference; Nextcloud supports dark mode natively |
| Secret tags (in addition to folders) | **Enterprise** | Cross-cutting categorization |
| Custom fields per secret type (admin-defined) | **Enterprise** | Organization-specific field requirements |
| Breach detection (HaveIBeenPwned) for secret URLs | **Enterprise** | Proactive security alerts for compromised URLs (1Password Watchtower) |
| Password age indicator | **Enterprise** | Show how old each secret is; flag stale credentials |
| Export to PDF (single secret) | **Enterprise** | Print-friendly credential sheet for offline backup (KeePassXC) |

### Encryption & Key Management

| Feature | Tier | Justification |
|---------|------|---------------|
| RSA-4096 encryption of secrets via EncryptionSuite | **MVP** | Core security — see [encryption-suites spec](../openspec/specs/encryption-suites/spec.md) |
| AES-256 encryption of private keys with master password | **MVP** | Private key protection |
| Private CA bootstrap (root + intermediate) on first setup | **MVP** | Certificate infrastructure |
| Automatic EncryptionSuite creation on first login | **MVP** | Zero-friction onboarding |
| Master password session with configurable timeout | **MVP** | Balance security and convenience |
| Lock screen (full page, not overlay) | **MVP** | Session expiry handling |
| Tab-close session clearing | **MVP** | Prevent stale sessions |
| Master password strength enforcement (zxcvbn ≥ 3) | **MVP** | NIST-aligned password policy |
| Live password strength feedback | **MVP** | User guidance during setup |
| Routine master password change (re-wrap private key) | **MVP** | Password hygiene |
| Compromise recovery (full key rotation + migration) | **MVP** | Security incident response |
| Suite migration with per-secret error tracking | **MVP** | Reliable recovery |
| Suite revocation and reinstatement | **MVP** | Admin control |
| CA certificate auto-renewal (intermediate) | **V1** | Operational continuity |
| CA health check in admin panel | **V1** | Admin visibility |
| Admin-configurable minimum password length and score | **V1** | Policy enforcement |
| Root certificate manual renewal with admin notifications | **V1** | Planned lifecycle management |
| Forced intermediate renewal (leaked key scenario) | **V1** | Emergency response |
| Password expiry reminders for secrets | **Enterprise** | Credential rotation prompts |
| Multiple encryption suites per user (key rotation) | **Enterprise** | Advanced key management |
| Custom CA chain upload | **Enterprise** | Integrate with existing PKI |
| Post-quantum cryptography (when PHP supports it) | **Enterprise** | Future-proofing |

### Key Generator

| Feature | Tier | Justification |
|---------|------|---------------|
| Random key generation with configurable length | **MVP** | See [key-generator spec](../openspec/specs/key-generator/spec.md) |
| Special character toggle (OWASP set) | **MVP** | Accommodate target system requirements |
| Character exclusion | **MVP** | Avoid ambiguous characters |
| Regex override for advanced patterns | **MVP** | Developer-friendly power feature |
| Integration with secret creation UI | **MVP** | Seamless workflow |
| Key generation API endpoint | **MVP** | Programmatic access |
| Password strength indicator on generated keys | **V1** | Visual feedback |
| Pronounceable password option | **V1** | Human-friendly passwords |
| Passphrase generation (word-based) | **V1** | Diceware-style passphrases |

### User Sharing

| Feature | Tier | Justification |
|---------|------|---------------|
| Share secret with Nextcloud user (encrypted copy) | **MVP** | See [user-sharing spec](../openspec/specs/user-sharing/spec.md) |
| Sync-on-update (changes propagate to all copies) | **MVP** | Shared secrets stay current |
| Share with Nextcloud group (static expansion) | **MVP** | Team sharing |
| Notification on share received | **MVP** | User awareness |
| Revoke share (delete recipient's copy) | **MVP** | Access control |
| Share visibility (owner sees recipients; recipients don't) | **MVP** | Privacy by design |
| Share request (recipient asks owner to share with third party) | **MVP** | Controlled re-sharing |
| New group member notification + approval | **MVP** | Owner control over group expansion |
| Auto-revoke on group member leave | **MVP** | Automatic access cleanup |
| Ownership delegation (admin power grab, user self-delegation) | **V1** | Continuity when owner is unavailable |
| Delegation reclaim | **V1** | Owner regains control |
| Permanent delegation on suite revocation | **V1** | Graceful ownership transfer |
| Compromised suite → owner notification for shared secrets | **V1** | Security incident awareness |

### Link Sharing

| Feature | Tier | Justification |
|---------|------|---------------|
| Password-protected share link with usage limit | **MVP** | See [link-sharing spec](../openspec/specs/link-sharing/spec.md) |
| Argon2id KDF for snapshot encryption | **MVP** | Memory-hard protection |
| Brute-force protection (5 attempts → auto-delete) | **MVP** | Hostile access defense |
| Point-in-time snapshot (intentional staleness) | **MVP** | Predictable behavior |
| Multiple concurrent link shares per secret | **MVP** | Flexibility |
| Manual link revocation | **MVP** | Owner control |
| Link share expiry (optional) | **V1** | Time-limited access |
| Link share access audit log | **Enterprise** | Compliance tracking |

### Secret Requests

| Feature | Tier | Justification |
|---------|------|---------------|
| Fill-in link for write-without-read submission | **MVP** | See [secret-requests spec](../openspec/specs/secret-requests/spec.md) |
| Request for own secrets and application secrets | **MVP** | Dual use case |
| Notification on fulfillment | **MVP** | Requester awareness |
| Field validation (all requested fields non-empty) | **MVP** | Data completeness |
| Write-once semantics | **MVP** | Security guarantee |
| Re-request (credential rotation via new fill-in link) | **MVP** | Operational workflow |
| Optional request expiry | **V1** | Time-limited requests |
| Request audit trail | **Enterprise** | Compliance tracking |

### Application Management

| Feature | Tier | Justification |
|---------|------|---------------|
| Application registration (any user, incl. anonymous) | **MVP** | See [application-mgmt spec](../openspec/specs/application-mgmt/spec.md) |
| Approval queue for non-admin registrations | **MVP** | Admin control |
| EncryptionSuite via CSR (app manages own private key) | **MVP** | Standard PKI pattern |
| EncryptionSuite via generated key pair (private key returned once) | **MVP** | Convenience option |
| Admin notification on pending registration | **MVP** | Timely approval |
| Pending applications counter on dashboard | **MVP** | Admin visibility |
| Application deletion (hard delete with cascade) | **MVP** | Lifecycle management |
| Write secret for application (write-without-read) | **MVP** | Core security pattern |
| Application API authentication (RFC 7523 JWT Bearer) | **V1** | Standardized API access |
| Application secret retrieval via REST API | **V1** | Programmatic consumption |
| OpenConnector integration (secret store for connectors) | **V1** | Sister app integration |

### Dashboard & Reporting

| Feature | Tier | Justification |
|---------|------|---------------|
| Dashboard with vault summary (total secrets, shared, folders) | **MVP** | At-a-glance overview |
| Vault health indicator (compromised secrets, migration status) | **MVP** | Security awareness |
| Pending applications counter (admin only) | **MVP** | Admin actionability |
| CA health status card (admin only) | **V1** | Certificate lifecycle visibility |
| Recently accessed secrets widget | **V1** | Quick access |
| Sharing activity summary | **V1** | Collaboration overview |
| Password health report (weak, reused, old passwords) | **Enterprise** | Security audit |
| Breach detection (HaveIBeenPwned integration) | **Enterprise** | Proactive security |
| Vault usage analytics (admin) | **Enterprise** | Adoption tracking |

### Admin Settings

| Feature | Tier | Justification |
|---------|------|---------------|
| Nextcloud admin settings page | **MVP** | App configuration |
| CA health status display | **MVP** | Certificate monitoring |
| Master password minimum length configuration (12–20) | **MVP** | Policy enforcement |
| Master password minimum score configuration (3–4) | **MVP** | Strength floor |
| Application approval queue | **MVP** | Registration management |
| Session timeout global default | **V1** | Org-wide policy |
| CA certificate details and expiry dates | **V1** | Certificate inspection |
| Forced intermediate renewal button | **V1** | Emergency action |
| CA bootstrap retry button | **V1** | Recovery from failed setup |
| Global secret type management | **V1** | Organization-wide types |
| Secret type CRUD for admin-created global types | **V1** | Customization |
| Vault statistics (total users, secrets, shares) | **Enterprise** | Usage overview |

### User Settings (NcAppSettingsDialog)

| Feature | Tier | Justification |
|---------|------|---------------|
| Session timeout preference (Nextcloud session / 10 min / 30 min) | **MVP** | Per-user security/convenience balance |
| Notification toggle: secret shared with me | **MVP** | Notification control |
| Notification toggle: secret request fulfilled | **MVP** | Notification control |
| Notification toggle: group share additions | **V1** | Fine-grained control |
| Notification toggle: compromise alerts | **V1** | Security notifications |
| Default secret type preference | **V1** | Workflow customization |
| Default view preference (list / folder tree) | **V1** | Display personalization |

### Notifications (OCP\Notification\IManager)

| Event | Subject Key | Setting Category | Recipient Logic | Tier |
|-------|-------------|-----------------|-----------------|------|
| Secret shared with user | `secret_shared` | `notify_shares` | Notify recipient | **MVP** |
| Secret request fulfilled | `request_fulfilled` | `notify_requests` | Notify requester | **MVP** |
| Application pending approval | `app_pending` | — (always notify admins) | All vault_admins | **MVP** |
| Group share: new member needs approval | `group_member_added` | `notify_group_shares` | Notify secret owner | **MVP** |
| Share request from recipient | `share_request` | `notify_shares` | Notify secret owner | **MVP** |
| Share request approved/denied | `share_request_result` | `notify_shares` | Notify requester | **MVP** |
| CA certificate expiring (90/30/7 days) | `ca_expiring` | — (always notify admins) | All admins | **V1** |
| Intermediate auto-renewed | `ca_renewed` | — (always notify admins) | All admins | **V1** |
| Shared secret possibly compromised | `secret_compromised` | `notify_security` | Notify original owner | **V1** |
| Suite revoked by admin | `suite_revoked` | `notify_security` | Notify suite owner | **V1** |

**Backend pattern:** `NotificationService` with `SUBJECT_SETTING_MAP` constant mapping subjects to user setting keys.

### Security & Compliance

| Feature | Tier | Justification |
|---------|------|---------------|
| End-to-end RSA encryption at rest | **MVP** | Core security model |
| Master password never stored (session-only AES key) | **MVP** | Zero-knowledge principle |
| Write-without-read for application secrets and requests | **MVP** | Prevent credential leakage |
| WCAG AA compliance | **MVP** | Accessibility requirement |
| English + Dutch localization | **MVP** | Primary markets |
| NL Design System theming support | **V1** | Government visual compliance |
| GDPR data export (all user secrets + metadata) | **V1** | Right of access |
| GDPR data deletion (user + all shares) | **V1** | Right to erasure |
| Audit trail on all secret operations | **V1** | Accountability |
| Field-level encryption audit (verify encrypted fields) | **Enterprise** | Compliance verification |
| Data retention policies | **Enterprise** | Automated cleanup |

### Integration

| Feature | Tier | Justification |
|---------|------|---------------|
| Nextcloud unified search (name + URL) | **MVP** | Platform integration |
| Nextcloud notifications (shares, requests, CA) | **MVP** | Platform integration |
| REST API for all operations | **V1** | Programmatic access |
| OpenConnector secret store integration | **V1** | Sister app integration |
| Browser extension (Bitwarden-compatible API subset) | **Enterprise** | Auto-fill in browser |
| CLI tool for secret management | **Enterprise** | DevOps workflow |
| Nextcloud Flows automation triggers | **Enterprise** | Low-code integration |

## 3. Settings & Notifications (Derived from Features)

### 3.1 Admin Settings (IAppConfig)

| Setting | Feature Source | Type | Default | Tier |
|---------|---------------|------|---------|------|
| `min_password_length` | Master password strength | int | 12 | **MVP** |
| `min_password_score` | Master password strength | int (3–4) | 3 | **MVP** |
| `default_session_timeout` | Session mechanism | enum (session/10min/30min) | session | **V1** |
| `ca_auto_renew_enabled` | CA renewal | bool | true | **V1** |
| `ca_expiry_notification_days` | CA health | JSON array | [90, 30, 7] | **V1** |

### 3.2 User Settings (OCP\IConfig, NcAppSettingsDialog)

| Setting | Feature Source | Type | Default | Tier |
|---------|---------------|------|---------|------|
| `session_timeout` | Session mechanism | enum (session/10min/30min) | (admin default) | **MVP** |
| `notify_shares` | User sharing | bool | true | **MVP** |
| `notify_requests` | Secret requests | bool | true | **MVP** |
| `notify_group_shares` | Group sharing | bool | true | **V1** |
| `notify_security` | Compromise alerts | bool | true | **V1** |
| `default_secret_type` | Secret creation | string | login | **V1** |
| `default_view` | Vault navigation | enum (list/folders) | list | **V1** |

### 3.3 Notifications (OCP\Notification\IManager)

See the Notifications table in Section 2. Each notification event maps to a user setting toggle category. Admin notifications (CA expiry, pending applications) are always delivered and cannot be disabled.

## 4. Gap Analysis

### What Competitors Do Well

- **Bitwarden/Vaultwarden**: Massive ecosystem (browser extensions, mobile apps, CLI), FIDO2/WebAuthn, organization vaults with collections, Send for ephemeral sharing, password health reports, breach detection
- **1Password**: Best-in-class UX, Watchtower (security audit), service accounts for CI/CD, SSH agent integration, developer tools
- **HashiCorp Vault**: Dynamic secrets (auto-rotating), transit encryption engine, policy-as-code, namespaces for multi-tenancy, comprehensive audit logging
- **Passbolt**: True E2E encryption with GPG, team-first design, RBAC with fine-grained permissions, LDAP/AD integration

### What They Lack

| Gap | Opportunity for Doriath |
|-----|------------------------|
| No Nextcloud integration | Doriath lives in the collaboration platform — users, groups, search, notifications are native |
| No write-without-read | Only Doriath (via asymmetric encryption) lets admins request secrets they can never read |
| No private CA with user certificates | Doriath's PKI infrastructure enables certificate-based identity, not just password storage |
| No application secret management via CSR | Standard PKI pattern for onboarding applications — competitors use API tokens or service accounts |
| No request-based credential provisioning | Secret requests (fill-in links) are unique to Doriath |
| SaaS data sovereignty concerns | Bitwarden/1Password/LastPass store encrypted data on third-party infrastructure |
| No government-first design | No competitor targets Dutch public sector or supports NL Design System |
| Infrastructure vs. user tool split | HashiCorp Vault is too complex for end users; Bitwarden has no infrastructure features |

### Nextcloud-Native Advantages

| Capability | Why Competitors Cannot Match It |
|------------|-------------------------------|
| Zero-cost identity layer | Nextcloud users and groups are the sharing model — no separate user directory |
| Unified search integration | Secrets discoverable from Nextcloud's Ctrl+F — no competitor can inject into another platform's search |
| Native notifications | Share alerts, request fulfillment, CA warnings via the bell icon — no separate notification system |
| Group-based sharing | Leverage Nextcloud group membership for team secret access — automatic, no manual sync |
| User lifecycle integration | IUserDeletedEvent cleans up vaults automatically — no orphaned data |
| Sovereign deployment | Same Nextcloud instance, same server, same backup — no external SaaS dependency |
| OpenConnector secret store | Direct integration with Conduction's connector framework — no competitor can offer this |

## 5. Strategic Positioning

### Positioning Statement

**Doriath is the vault that lives where your team already works.** Built natively into Nextcloud, it provides enterprise-grade encrypted secret management — for humans and applications — without leaving your collaboration platform.

### Differentiation Strategy

Three pillars:

1. **Platform leverage** — Nextcloud provides identity, groups, search, notifications, and files. Doriath orchestrates them for secret management instead of rebuilding them.
2. **PKI-native architecture** — Unlike password managers that bolt on encryption, Doriath is built on a private Certificate Authority with X.509 certificates. This enables write-without-read, application CSR onboarding, and a foundation for future Certificate Authority functionality.
3. **Government-first, enterprise-ready** — NL Design System theming, sovereign self-hosted deployment, WCAG AA compliance, and a path to becoming the reference secrets manager for Dutch public sector organizations.

### Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Feature gap vs. Bitwarden (browser extension, mobile, FIDO2) | High | Focus on what Bitwarden can't do: Nextcloud integration, write-without-read, application secrets. Browser extension is Enterprise tier. |
| Passwords app incumbency on Nextcloud | High | Differentiate on encryption architecture (PKI vs. SSE), application secrets, and enterprise features. Consider migration tooling. |
| No mobile app | Medium | Nextcloud's mobile apps provide the session; Doriath is web-first. Mobile vault is a future consideration. |
| Complexity of PKI for end users | Medium | Zero-friction onboarding: EncryptionSuite auto-created on first login. Users only interact with master password, never with certificates. |
| Master password lost = data lost | High | This is by design (zero-knowledge). Document clearly. Consider emergency access (V1) or admin recovery mechanisms (Enterprise). |
| Small team | High | Own-DB architecture means more backend code than thin-client apps. Prioritize MVP ruthlessly. |

## 6. Recommended Feature Set Summary

### MVP (45 features)

A fully functional encrypted vault for Nextcloud users and applications. Replaces spreadsheets and insecure credential sharing.

**Core Secret Management**
1. Secret CRUD (name, key, login, url, additional fields)
2. Secret types (6 system types + custom user/global types)
3. Folder organization (tree hierarchy per user)
4. Folder CRUD with cascade options
5. Secret list with search, sort, pagination
6. Copy-to-clipboard on secret list items
7. Show/hide toggle on password fields
8. Favicon/icon next to secrets by URL
9. Secret detail view with type-specific fields
10. Fuzzy search by name and URL
11. Nextcloud unified search integration
12. Deep-link from search via lock screen

**Encryption & Security**
13. RSA-4096 encryption via EncryptionSuite
14. AES-256 private key protection
15. Private CA bootstrap (root + intermediate)
16. Auto-create EncryptionSuite on first login
17. Master password session with configurable timeout
18. Lock screen (full page)
19. Tab-close session clearing
20. Master password strength enforcement (zxcvbn)
21. Live strength feedback
22. Routine master password change
23. Compromise recovery with key rotation
24. Suite migration with error tracking
25. Suite revocation and reinstatement

**Key Generator**
26. Random key generation with configurable length
27. Special character toggle (OWASP set)
28. Character exclusion
29. Regex override
30. Integration with secret creation UI
31. Key generation API endpoint

**Sharing**
32. Share with Nextcloud user (encrypted copy)
33. Sync-on-update
34. Share with group (static expansion)
35. Share notification
36. Revoke share
37. Share visibility (owner-only recipient list)
38. Share request mechanism
39. Group member notification + approval
40. Auto-revoke on group leave

**Link Sharing & Requests**
41. Password-protected link with usage limit
42. Fill-in link for write-without-read submission
43. Request notification on fulfillment

**Application Management**
44. Application registration with approval queue
45. EncryptionSuite via CSR or generated key pair

### V1 (30 additional features)

Enterprise-ready vault with full lifecycle management and API access.

46. Ownership delegation and reclaim
47. Permanent delegation on suite revocation
48. Compromised suite owner notification
49. Link share expiry
50. Re-request for credential rotation
51. Application API (RFC 7523 JWT Bearer)
52. OpenConnector integration
53. CA auto-renewal and health check
54. Admin-configurable password policy
55. Root certificate renewal with notifications
56. Forced intermediate renewal
57. Secret import (CSV, Bitwarden JSON, KeePass XML)
58. Secret export (encrypted backup, CSV)
59. Favorite/pinned secrets
60. Recently accessed secrets
61. Password health scoring per secret
62. Secret strength indicator in list view
63. Vault search with keyboard shortcut (Ctrl+K)
64. Dark mode support
65. NL Design System theming
66. GDPR export + deletion
67. Audit trail on secret operations
68. REST API for all operations
69. CA certificate details in admin panel
70. Global secret type management
71. Notification toggles (group shares, security)
72. Default secret type and view preferences
73. Password strength indicator on generated keys
74. Pronounceable password and passphrase generation
75. Bulk operations (delete, move folder)

### Enterprise (15 additional features)

Large organizations, multi-instance deployments, and compliance-driven environments.

76. Password health report (weak, reused, old)
77. Breach detection (HaveIBeenPwned integration)
78. Breach detection for secret URLs (HaveIBeenPwned)
79. Password age indicator
80. Export to PDF (single secret)
81. Browser extension (Bitwarden-compatible API subset)
82. CLI tool for secret management
83. Multiple encryption suites per user (key rotation)
84. Custom CA chain upload
85. Post-quantum cryptography
86. Secret tags
87. Custom fields per secret type
88. Field-level encryption audit
89. Data retention policies
90. Nextcloud Flows automation triggers
