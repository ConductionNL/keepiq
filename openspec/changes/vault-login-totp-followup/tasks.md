# Tasks: follow-up to one-time codes and passkeys on a login

Moved from `vault-login-totp-codes` (tasks 7.1-7.4), where they were deferred by design.

## 1. Deferred items

- [ ] 1.1 Clone and print carry both fields. Deferred: neither feature exists yet; belongs to `vault-item-clone-preview-and-print`.
  - Open, a decision (20 Nov 2026): clone and print now exist (archived `vault-item-clone-preview-and-print`), and print never shows the seed (its D4). Its spec says a clone MUST NOT copy passkey material, because a passkey credential is bound to one item, while this task says a clone carries `passkeys`. Should a clone carry the seed only, both, or neither?
- [ ] 1.2 The Passkey type filter also finds logins with passkeys. Deferred: the server cannot see inside the ciphertext; needs a backend query design.
- [ ] 1.3 Guided move of standalone Authenticator and Passkey items, and of additional fields named `totp` or `otp`, onto their login. Dropped in keepiq#1328: only internal testers hold such items, so they stay as they are (D12 of `vault-login-totp-codes`).
- [x] 1.4 Android reads and writes both fields. Deferred to keepiq#1329.
  - Done in keepiq#1349 (`mobile-login-totp-passkeys`), which closed keepiq#1329 as completed on 2 Nov 2026: the item detail, autofill and credential provider read a login's `totp` and `passkeys`, and a new passkey is saved onto the login. Its instrumented tests run in `mobile-e2e.yml` (tasks 2.4 and 3.4 of that change).
