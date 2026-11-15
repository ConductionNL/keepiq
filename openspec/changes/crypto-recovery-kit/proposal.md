---
kind: code
---

# Regain access with a recovery kit when the master password is lost

## Why

A user who forgets their master password cannot reopen their own vault. What keepiq has today goes around the vault, not back into it: a passphrase-encrypted backup restores the secrets into a new vault after an administrator force-revokes the old key (`src/export/backup.js`, `src/import/backupParser.js`), a trusted contact gets read access after a wait (`src/views/EmergencyAccessView.vue`), and organisation account recovery needs a threshold of recovery officers (`openspec/specs/organisation-account-recovery`). `grep -rni 'recovery code\|recovery kit' src lib` finds nothing. Keeper gives every user a recovery phrase for exactly this case.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

- `crypto-12` Regain access yourself with a recovery code or kit when the master password is lost. State `specified`, plan this change (decision 399).

## What changes

- The user creates a recovery kit in their personal settings. The browser generates a random recovery code, derives a key from it with Argon2id, wraps the user's suite private key with that key and sends only the wrapped copy. The kit is a printable page and a text file with the code, the account and the date.
- The lock screen offers "I lost my master password". The user enters the recovery code; the browser fetches the wrapped copy, unwraps the private key and asks for a new master password, which goes through the routine master password change (`openspec/specs/encryption-suites`, Master Password Change, Routine).
- A used kit is spent. After recovery, and whenever the user creates a new kit, the old wrapped copy is deleted and the user is offered a new kit.
- The server never sees the recovery code or the unwrapped key. A recovery attempt is rate limited and written to the audit trail.

## Out of scope

- Administrator or officer recovery: that is `organisation-account-recovery`.
- Recovering a vault whose suite key was force-revoked: the kit wraps the current suite key only.

## Impact

- `lib/`: a store for one wrapped recovery copy per user and suite, routes to create, fetch and delete it, rate limiting and audit events.
- `src/crypto/`: recovery code generation and the wrap and unwrap, beside `emergencyEnvelope.js` and `argon2.js`.
- `src/views/LockScreen.vue`, the personal settings and the export screen (board `KqExporteren`, where the kit sits beside the passphrase backup).
