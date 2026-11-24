# Tasks: follow-up to one-time codes and passkeys on a login

Moved from `vault-login-totp-codes` (tasks 7.1-7.4), where they were deferred by design.

## 1. Deferred items

- [x] 1.1 Clone and print carry both fields. Deferred: neither feature exists yet; belongs to `vault-item-clone-preview-and-print`.
  - Open, a decision (20 Nov 2026): clone and print now exist (archived `vault-item-clone-preview-and-print`), and print never shows the seed (its D4). Its spec says a clone MUST NOT copy passkey material, because a passkey credential is bound to one item, while this task says a clone carries `passkeys`. Should a clone carry the seed only, both, or neither?
  - Decided (24 Nov 2026): neither. A passkey is bound to one item (the clone spec already forbids copying it), and a clone is made for a different account, where the same seed would give a wrong code; the seed also stays write-only in the UI. This was already the shipped behaviour; now a requirement in `specs/login-one-time-codes`. Verify: `tests/dialogs/SecretCreateDialog.clone.spec.js` ("a clone of a login with a seed and passkeys saves neither").
- [x] 1.2 The Passkey type filter also finds logins with passkeys. Deferred: the server cannot see inside the ciphertext; needs a backend query design.
  - Decided and done (24 Nov 2026): no new flag. The server already tells a login with passkeys from one without by the presence of ciphertext (the vault list marker reads exactly that), so on the built-in Passkey type `SecretListFilter` also matches rows whose `passkeys` is not null and not empty; `SecretMapper` looks the type's name up once per filtered listing. Nothing is decrypted and nothing new is stored. Verify: `SecretListFilterTest`, `SecretMapperQueriesTest` (list and count).
- [x] 1.3 Guided move of standalone Authenticator and Passkey items, and of additional fields named `totp` or `otp`, onto their login. Dropped in keepiq#1328: only internal testers hold such items, so they stay as they are (D12 of `vault-login-totp-codes`).
  - Closed as dropped, not built: ticked so the change can be archived.
- [x] 1.4 Android reads and writes both fields. Deferred to keepiq#1329.
  - Done in keepiq#1349 (`mobile-login-totp-passkeys`), which closed keepiq#1329 as completed on 2 Nov 2026: the item detail, autofill and credential provider read a login's `totp` and `passkeys`, and a new passkey is saved onto the login. Its instrumented tests run in `mobile-e2e.yml` (tasks 2.4 and 3.4 of that change).
