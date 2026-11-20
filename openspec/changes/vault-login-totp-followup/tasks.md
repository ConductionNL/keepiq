# Tasks: follow-up to one-time codes and passkeys on a login

Moved from `vault-login-totp-codes` (tasks 7.1-7.4), where they were deferred by design.

## 1. Deferred items

- [ ] 1.1 Clone and print carry both fields. Deferred: neither feature exists yet; belongs to `vault-item-clone-preview-and-print`.
- [ ] 1.2 The Passkey type filter also finds logins with passkeys. Deferred: the server cannot see inside the ciphertext; needs a backend query design.
- [ ] 1.3 Guided move of standalone Authenticator and Passkey items, and of additional fields named `totp` or `otp`, onto their login. Dropped in keepiq#1328: only internal testers hold such items, so they stay as they are (D12 of `vault-login-totp-codes`).
- [ ] 1.4 Android reads and writes both fields. Deferred to keepiq#1329.
