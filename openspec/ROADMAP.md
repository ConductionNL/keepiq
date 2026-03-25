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

### Secrets — done

### Key Generator — pending
- [ ] Character set edge case: what if excluded characters exhaust the entire set?
- [ ] Precise definition of "special characters" (symbols vary by target system)
- [ ] Does the API endpoint require authentication, or is it open?

### Application Management — pending
- [ ] Application API authentication (how does an approved app authenticate to retrieve its secrets?)
- [ ] Application deactivation / deletion flow
- [ ] What happens to application secrets on deletion?
- [ ] Admin notification when pending registrations arrive

### User Sharing — pending
- [ ] Can a recipient re-share a secret further?
- [ ] What if the recipient's EncryptionSuite is revoked — does the share become permanently inaccessible?
- [ ] Do recipients get notified when a secret is shared with them?
- [ ] Does the recipient see who else the secret is shared with?
- [ ] Accept/reject mechanism — do shares just appear, or can the recipient refuse?

### Link Sharing — pending
- [ ] KDF specification (PBKDF2 vs Argon2 — noted in spec but undecided)
- [ ] Token entropy requirement
- [ ] Brute-force protection on password attempts
- [ ] Snapshot staleness: if the original secret changes after the link is created, the link shows stale data — is that intentional?
- [ ] Can there be multiple active link shares for the same secret simultaneously?

### Secret Requests — pending
- [ ] Token expiry — can a request stay pending indefinitely?
- [ ] Notification to requester when request is fulfilled
- [ ] Submitted field validation (what if the submitter sends empty values?)
- [ ] Can the same secret have multiple pending requests simultaneously?
- [ ] Rate limiting on the fill-in endpoint

---

## How This Works

1. Run `/opsx:app-explore` to define or refine features in `openspec/specs/`
2. When a feature is `planned`, add it to the table above
3. Run `/opsx:ff {feature-name}` to create the implementation spec
4. Update the **OpenSpec Change** column with a link to the change directory
5. When all changes for a feature are done, mark the feature `done`
