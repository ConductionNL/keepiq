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

## How This Works

1. Run `/opsx:app-explore` to define or refine features in `openspec/specs/`
2. When a feature is `planned`, add it to the table above
3. Run `/opsx:ff {feature-name}` to create the implementation spec
4. Update the **OpenSpec Change** column with a link to the change directory
5. When all changes for a feature are done, mark the feature `done`
