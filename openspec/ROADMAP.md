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

### Phase 4 — Future Development

_Noted in Vault-app.docx but explicitly out of scope for now._

- Encrypted mail integration
- Certificate Authority (public CA functionality)
- File encryption

---

## Spec Deepening Progress

Tracking remaining gaps to address per spec during `/opsx:app-explore` sessions.

### Encryption Suites — done

### Secrets — pending
- [ ] 🔴 Pagination approach: 50 items per page (standard pagination) or 30 items with dynamic infinite scroll? To be decided during UI design
- [ ] 🔴 Subfolder cascade: does `?cascade=delete` and `?cascade=move` apply recursively to subfolders, or only to direct contents? Unclear whether recursive folder deletion should be allowed

### Key Generator — done
- [x] Character set exhaustion: fail fast — reject if resolved set has fewer than 2 distinct characters
- [x] Special characters: OWASP recommended set (`!@#$%^&*()-_=+[]{}|;:,.<>?/`)
- [x] Authentication: endpoint requires valid Nextcloud session (HTTP 401 otherwise)

### Application Management — pending
- [ ] 🔴 Application API authentication — RFC 7523 (JWT Bearer / Private Key JWT) is the lean, needs team discussion before finalising
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
