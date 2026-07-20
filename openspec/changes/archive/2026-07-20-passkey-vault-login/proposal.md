---
kind: code
---

# Proposal: Passwordless vault unlock via WebAuthn PRF

## Why

Doriath's lock screen has exactly one unlock path today: the user types their
master password, the browser derives an AES key from it
(`src/crypto/aes.js:18` — `deriveAesKey(password, salt)`, PBKDF2-SHA256 at
600 000 iterations, `src/crypto/aes.js:9`), decrypts the AES-wrapped RSA
private key and imports it as a non-extractable WebCrypto `CryptoKey`
(`src/store/modules/session.js:59` — `unlock(masterPassword)`). There is no
alternative unlock envelope: `grep -rn "navigator.credentials|PublicKeyCredential|prf" src/ lib/`
returns nothing, so no WebAuthn code exists anywhere in the app.

This is an open competitive frontier. Doriath's own competitive analysis
records that Bitwarden is **the only vendor in the entire category with shipped
passkey vault-login** (`docs/FEATURES.md:494`) — its PRF-based web-vault unlock
plus Windows 11 passkey login shipped March 2026 — and that passkeys are now a
tier-1 expectation on the Nextcloud platform, not an Enterprise extra
(`docs/FEATURES.md:499`, citing NC Passwords wishlist issues #615/#792). The
canonical feature `passkey-vault-login` was logged in the Spectr register at
demand 60 with competitorCoverage 1 — i.e. one shipped competitor against
high demand, the definition of whitespace. External signals reinforce the
timing: FIDO's 2026 report puts passwordless at under 10% of workforce logins
(large headroom), and Microsoft Entra ID defaults new tenants to passkeys from
1 September 2026, normalizing the passwordless expectation for exactly the
public-sector tenants Doriath targets.

Crucially, this can be built without weakening ADR-003's always-E2E,
zero-knowledge guarantee. A WebAuthn authenticator's PRF extension yields a
high-entropy secret that never leaves the device; used as a key-encryption key
(KEK), it can wrap a *copy* of the user's vault unlock key — an **alternative
unlock envelope stored alongside the master-password envelope**, exactly the
re-wrap pattern `emergency-access` already uses to give a second party access
to key material (`src/crypto/emergencyEnvelope.js` — hybrid RSA-OAEP+AES-256-GCM
recipient envelope). The master-password KDF path stays canonical and always
available as fallback, so losing the authenticator loses nothing.

## What Changes

- **Enroll a passkey against an unlocked vault.** With the vault unlocked
  (vault unlock key in memory), the browser runs a WebAuthn ceremony with the
  `prf` extension, derives a KEK from the PRF output (HKDF-SHA256), AES-256-GCM
  wraps a copy of the vault unlock key, and stores the credential metadata plus
  the wrapped-key envelope server-side. Enrollment MUST require an unlocked
  vault — the wrap can only be produced when the plaintext unlock key is
  available.
- **Passwordless unlock at the lock screen.** When a passkey is enrolled and
  the browser supports PRF, the lock screen offers "Unlock with passkey":
  `navigator.credentials.get()` with the stored PRF salt → PRF output → HKDF →
  KEK → AES-GCM unwrap of the vault unlock key → decrypt the existing private-key
  blob → import non-extractable `CryptoKey`. The master password is never
  entered and is never sent to the server (unchanged from ADR-003).
- **Master password remains canonical fallback.** The master-password envelope
  is untouched; the lock screen always offers "Use master password". Losing or
  resetting the authenticator never causes data loss — the passkey envelope is
  purely additive.
- **Manage and revoke passkeys.** A user can list their enrolled passkeys
  (label, transports, last-used), enroll additional ones, and revoke any —
  revocation deletes the server-side envelope so that authenticator can no
  longer unlock.
- **Feature-detect and degrade honestly.** PRF support varies by
  browser/authenticator; the app MUST feature-detect (`PublicKeyCredential` +
  PRF availability) and hide the passkey option where unsupported, never
  blocking the master-password path.
- **Invalidate stale envelopes on unlock-key change.** A routine master-password
  change re-wraps the private key under a new AES key
  (encryption-suites spec, "Master Password Change — Routine"), so any existing
  passkey envelope wraps a now-dead key. Such envelopes MUST be marked stale and
  the user prompted to re-enroll; a compromise-recovery suite rotation MUST
  delete all passkey envelopes outright.
- **Explicitly out of scope for v1**: passkey-based *identity* login to
  Nextcloud itself (that is NC core's concern, not Doriath's), server-side
  WebAuthn assertion verification as an access-control gate (unlock success is
  proven by the client-side unwrap under E2E), and cross-device envelope sync
  beyond what the shared server storage already provides.

## Capabilities

### New Capabilities
- `passkey-vault-login`: Enrollment, passwordless unlock, management/revocation,
  feature detection, and stale-envelope handling for a WebAuthn-PRF alternative
  unlock envelope that coexists with the canonical master-password envelope.

### Modified Capabilities
<!-- None. The master-password unlock requirements in encryption-suites are
     unchanged; this change is purely additive (a second, optional envelope). -->

## Impact

- **New DB table**: `doriath_passkey_credentials` (credential metadata + PRF
  salt + wrapped-unlock-key envelope + staleness marker). Own Doctrine
  entity/migration per ADR-001 — no OpenRegister.
- **Backend**: new `PasskeyController` + `PasskeyService` + `PasskeyMapper`;
  challenge issuance and credential-registration binding; all endpoints
  `#[NoAdminRequired]` and owner-scoped (the lock screen runs under an active
  Nextcloud session — the *vault* is locked, not the NC session).
- **Frontend**: new WebAuthn/PRF crypto module (`src/crypto/passkey.js`), lock
  screen "Unlock with passkey" branch, a passkey-management settings section,
  and an enrollment flow. Reuses the envelope encode/decode helpers in
  `src/crypto/envelope.js`.
- **Crypto model**: no new algorithms — HKDF-SHA256 for KEK derivation
  (WebCrypto native), AES-256-GCM for the envelope (existing format), RSA
  private-key blob unchanged.
- **No impact on OpenConnector / internal-app `DecryptService`** — those use
  passphrase-derived keys, not the browser unlock path.
