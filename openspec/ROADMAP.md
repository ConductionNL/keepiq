# Roadmap

This document tracks the planned development of Doriath.

Features are defined in [`openspec/specs/`](specs/). When a feature reaches `planned` status it is listed here and an OpenSpec change is created with `/opsx:ff`.

## Status Overview

| Feature | Status | Priority | OpenSpec Change |
|---------|--------|----------|----------------|
| [Encryption Suites](specs/encryption-suites/spec.md) | planned | 1 — must be first | — |
| [Secrets](specs/secrets/spec.md) | planned | 2 | — |
| [Key Generator](specs/key-generator/spec.md) | planned | 3 — needed for secret creation UI | — |
| [Application Management](specs/application-mgmt/spec.md) | planned | 4 | — |
| [User Sharing](specs/user-sharing/spec.md) | planned | 5 | — |
| [Link Sharing](specs/link-sharing/spec.md) | planned | 6 | — |
| [Secret Requests](specs/secret-requests/spec.md) | planned | 7 | — |
| [Team Folder Sharing](specs/team-folder-sharing/spec.md) | done — live-verified 2026-07-18 | 8 — #1 ecosystem wish | [archive](changes/archive/2026-07-18-team-folder-sharing/) |
| [Browser Extension & Autofill](specs/browser-extension-autofill/spec.md) | in-progress | 9 | [browser-extension-autofill](changes/browser-extension-autofill/) |
| [Passkey Item Type](specs/passkey-item-type/spec.md) | done — live-verified 2026-07-18 | 10 — 2026 table stakes | [archive](changes/archive/2026-07-18-passkey-item-type/) |
| [CXF Import/Export](specs/cxf-import-export/spec.md) | done — 2026-07-18 | 11 — after passkeys | [archive](changes/archive/2026-07-18-cxf-import-export/) |
| [Encrypted Attachments](specs/encrypted-attachments/spec.md) | in-progress | 12 | [encrypted-attachments](changes/encrypted-attachments/) |
| [Secret Version History](specs/secret-version-history/spec.md) | in-progress | 13 | [secret-version-history](changes/secret-version-history/) |
| [Rotation & Expiry Policies](specs/rotation-expiry-policies/spec.md) | in-progress | 14 — NIS2/BIO2 driver | [rotation-expiry-policies](changes/rotation-expiry-policies/) |
| [Machine Secret Leases](specs/machine-secret-leases/spec.md) | in-progress | 15 — NHI segment | [machine-secret-leases](changes/machine-secret-leases/) |
| [Extension Passkey Provider](specs/extension-passkey-provider/spec.md) | in-progress | 16 — makes passkeys usable | [extension-passkey-provider](changes/extension-passkey-provider/) |
| [Extension TOTP Autofill](specs/extension-totp-autofill/spec.md) | in-progress | 17 | [extension-totp-autofill](changes/extension-totp-autofill/) |
| [Passkey Vault Login](specs/passkey-vault-login/spec.md) | in-progress | 18 — PRF frontier | [passkey-vault-login](changes/passkey-vault-login/) |
| [Offline Read-only Cache](specs/offline-readonly-cache/spec.md) | in-progress | 19 | [offline-readonly-cache](changes/offline-readonly-cache/) |
| [Folder Permission Grades](specs/folder-permission-grades/spec.md) | in-progress | 20 — after team folders | [folder-permission-grades](changes/folder-permission-grades/) |
| [Ephemeral Send](specs/ephemeral-send/spec.md) | in-progress | 21 | [ephemeral-send](changes/ephemeral-send/) |
| [Compliance Reporting](specs/compliance-reporting/spec.md) | in-progress | 22 — BIO2/NIS2 evidence | [compliance-reporting](changes/compliance-reporting/) |
| [SIEM Audit Export](specs/siem-audit-export/spec.md) | in-progress | 23 | [siem-audit-export](changes/siem-audit-export/) |
| [Org Password Policies](specs/org-password-policies/spec.md) | in-progress | 24 | [org-password-policies](changes/org-password-policies/) |
| [Card & Identity Items](specs/card-identity-items/spec.md) | in-progress | 25 | [card-identity-items](changes/card-identity-items/) |
| [Bulk Actions](specs/bulk-actions/spec.md) | in-progress | 26 | [bulk-actions](changes/bulk-actions/) |
| [Mobile PWA](specs/mobile-pwa/spec.md) | in-progress | 27 | [mobile-pwa](changes/mobile-pwa/) |
| [Doriath CLI](specs/doriath-cli/spec.md) | in-progress | 28 | [doriath-cli](changes/doriath-cli/) |
| [CXP Transfer](specs/cxp-transfer/spec.md) | in-progress | 29 — after CXF | [cxp-transfer](changes/cxp-transfer/) |
| [Certificate Lifecycle](specs/certificate-lifecycle/spec.md) | in-progress | 30 | [certificate-lifecycle](changes/certificate-lifecycle/) |
| [Honey Credentials](specs/honey-credentials/spec.md) | in-progress | 31 — deception differentiator | [honey-credentials](changes/honey-credentials/) |

## Phases

### Phase 1 — Foundation (Core)

The minimum set that makes Doriath useful as a secrets manager.

1. **Encryption Suites** — CA bootstrap, key pair generation, user setup on first login
2. **Secrets** — CRUD with RSA encryption at rest
3. **Key Generator** — random key generation integrated into secret creation

### Phase 2 — Sharing (Core)

Features that enable secrets to move between parties.

4. **Application Management** — register apps, approval queue, CSR-based EncryptionSuite
5. **User Sharing** — copy + re-encrypt for Nextcloud users, sync on change
6. **Link Sharing** — password-protected links with usage limits
7. **Secret Requests** — fill-in links for write-without-read secrets

### Phase 3 — Advanced (Future)

_Not yet specced. To be explored in future `/opsx:app-explore` sessions._

- Multiple encryption suites per owner (key rotation, compromise recovery)
- API (basic auth + OAuth)
- Custom CA chain upload
- Post-quantum cryptography (when available in PHP)

### Phase 3.5 — Market-gap wave (specced 2026-07-16, deep-research driven)

Eight changes created from the 2026-07-16 competitive deep-dive (results logged to the Spectr register, `sourceRef: deepdive-2026-07-16-app-doriath`). Ordered by demand evidence:

1. **Team Folder Sharing** — share folders with users/groups, inheritance + offboarding (NC Passwords #582/#583, 63+60 reactions — the ecosystem's #1 wish)
2. **Browser Extension & Autofill** — MV3 extension, zero-knowledge unlock, iframe-capable autofill (the #1 experiential complaint ecosystem-wide)
3. **Passkey Item Type** — passkey storage as a secret type (2026 table stakes; Entra ID defaults to passkeys Sept 2026)
4. **CXF Import/Export** — FIDO Credential Exchange Format portability (Proposed Standard Aug 2025; kills the lock-in objection)
5. **Encrypted Attachments** — client-side-encrypted files on secrets (tier-1 table stakes)
6. **Secret Version History** — view/restore prior values (Passbolt v5.7 parity)
7. **Rotation & Expiry Policies** — expiry reminders + rotate-after-breach (NIS2 Art. 21(2)(j) + BIO2 compliance sale)
8. **Machine Secret Leases** — TTL-bounded scoped grants on the machine API (NHI market ~22% CAGR; unified human+machine store differentiator)

### Phase 3.6 — Market-gap wave 2 (specced 2026-07-17)

Sixteen follow-up changes from the same 2026-07-16 deep-dive: wave-1's deferred seams, tier-2/3 differentiators, and the BIO2/NIS2 compliance surface.

**Extension follow-ups**: `extension-passkey-provider` (WebAuthn provider — makes stored passkeys usable), `extension-totp-autofill` (codes in the extension, auto-copy on fill)
**Unlock & offline**: `passkey-vault-login` (PRF unlock — only Bitwarden has it), `offline-readonly-cache` (encrypted offline vault)
**Sharing follow-ups**: `folder-permission-grades` (read/write grades — wave-1's declared v2), `ephemeral-send` (burn-after-read ad-hoc sends)
**Compliance (BIO2/NIS2)**: `compliance-reporting` (auditor evidence export — no competitor has a BIO2/NIS2-framed report), `siem-audit-export` (syslog/webhook streaming), `org-password-policies` (admin generator/strength policies)
**Item types & UX**: `card-identity-items` (card + identity types, BSN-aware), `bulk-actions` (multi-select operations), `mobile-pwa` (installable PWA)
**Machine/dev**: `doriath-cli` (human + CI modes), `cxp-transfer` (HPKE provider-to-provider migration), `certificate-lifecycle` (inventory/renewal on the private CA), `honey-credentials` (decoy tripwires — deception is one of few detection controls a zero-knowledge store can offer)

### Phase 4 — Future Development

_Noted in Vault-app.docx but explicitly out of scope for now._

- Encrypted mail integration
- Certificate Authority (public CA functionality)
- File encryption

---

## Spec Deepening Progress

Tracking remaining gaps to address per spec during `/opsx:app-explore` sessions.

### Encryption Suites — done

### Secrets — done
- [x] Pagination approach: classic pagination, 50 items per page (team preference)
- [x] Subfolder cascade: user-directed resolution — frontend dialog lets user choose per subfolder (delete/move/keep); simple cascade params for folders without subfolders

### Key Generator — done
- [x] Character set exhaustion: fail fast — reject if resolved set has fewer than 2 distinct characters
- [x] Special characters: OWASP recommended set (`!@#$%^&*()-_=+[]{}|;:,.<>?/`)
- [x] Authentication: endpoint requires valid Nextcloud session (HTTP 401 otherwise)

### Application Management — done
- [x] Application API authentication — RFC 7523 (JWT Bearer / Private Key JWT) chosen; may revisit if implementation reveals blockers
- [x] Deletion: hard delete only, no deactivation state; removes application, EncryptionSuite, and all secrets
- [x] Admin notification: Nextcloud built-in notifications dispatched to all vault_admins on pending registration
- [x] Dashboard counter: pending application count shown to vault_admins, links to approval queue

### User Sharing — pending
- [x] Re-sharing: recipients submit a share request to the original owner; owner approves → system creates a direct share from owner to the requested user; requester notified of outcome only (no share list visibility)
- [x] Group sharing: static expansion at share time; new member joins → owner notified to approve; member leaves → group-derived shares auto-revoked; direct shares unaffected
- [x] Ownership delegation: admin power grab (any secret shared with them) or user self-delegation (to any existing recipient); multiple simultaneous delegates allowed; owner can reclaim all delegations; permanent on original owner's suite revocation/deletion
- [ ] 🔵 Future: mandatory admin share on secret creation (policy enforcement) — flagged for later exploration
- [x] Revoked suite: cascade-delete all shares and copies for that recipient
- [x] Compromised suite: migration covers shared copies; original owner notified to replace value; sync-on-update unsets `possibly_compromised_at` on all copies
- [x] Recipients notified via Nextcloud notification when a secret is shared with them
- [x] Share visibility: only original owner sees full recipient list; recipients see nothing beyond their own copy
- [x] Accept/reject: no accept/reject on direct shares — share request mechanism replaces re-sharing

### Link Sharing — done
- [x] KDF: Argon2id (memory-hard, PHP 8.3+ native)
- [x] Token entropy: minimum 128 bits via `random_bytes()`
- [x] Brute-force protection: 5 consecutive failed attempts permanently deletes the link share
- [x] Snapshot staleness: intentional — links serve point-in-time snapshots; owner must revoke and re-create to share updated values
- [x] Multiple concurrent link shares per secret: allowed, each with independent lifecycle

### Secret Requests — done
- [x] Token expiry: optional — requester can set `expires_at` at creation; no forced expiry
- [x] Notification: requester receives Nextcloud notification on fulfillment
- [x] Field validation: all requested fields must be non-empty; partial submissions rejected
- [x] Multiple requests per secret: N/A — each SecretRequest creates its own unfilled Secret
- [x] Rate limiting: standard Nextcloud rate limiting sufficient; no per-token limiting needed

---

## How This Works

1. Run `/opsx:app-explore` to define or refine features in `openspec/specs/`
2. When a feature is `planned`, add it to the table above
3. Run `/opsx:ff {feature-name}` to create the implementation spec
4. Update the **OpenSpec Change** column with a link to the change directory
5. When all changes for a feature are done, mark the feature `done`
