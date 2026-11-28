---
kind: code
---

# Follow-up to one-time codes and passkeys on a login

## Why

`vault-login-totp-codes` put a TOTP seed and passkeys on a login's own encrypted `totp` and `passkeys` fields in the web app. Four items were left out of it by design (its tasks 7.1-7.4 and the Deferred section of its design): each depends on a feature or a design that did not exist yet. They are kept here so that change could be archived with every task it owns done, and so the open items stay visible.

## What Changes

- **Clone and print carry both fields.** Once `vault-item-clone-preview-and-print` adds cloning and printing, a clone of a login carries its `totp` and `passkeys`, and printing never prints the seed or a passkey private key.
- **The Passkey type filter also finds logins with passkeys.** The server stores `passkeys` as ciphertext and cannot see inside it, so this needs a backend query design (for example a plain presence flag) before it can be built.
- **Standalone Authenticator and Passkey items stay as they are.** The guided move of such items, and of additional fields named `totp` or `otp`, onto their login was dropped in keepiq#1328 (design decision D12 of `vault-login-totp-codes`): only internal testers hold such items. It is recorded here as closed, not as work.
- **Android reads and writes both fields**, tracked in keepiq#1329.

## Capabilities

### Modified Capabilities

- `login-one-time-codes`: clone, print, the type filter and the Android client handle a login's code and passkeys. Spec deltas are written when each item is designed.

## Impact

- **Frontend**: the clone and print actions of `vault-item-clone-preview-and-print`, and the vault list type filter.
- **Backend**: a way to filter on the presence of passkeys without decrypting them.
- **Android**: keepiq#1329.
- **Security**: the seed stays write-only in the UI and no passkey private key is rendered or printed, as in `vault-login-totp-codes`.
- **Cross-app**: none.
