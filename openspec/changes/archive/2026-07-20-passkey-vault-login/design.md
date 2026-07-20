## Context

Doriath is always-E2E and zero-knowledge (ADR-003): the master password never
leaves the browser, and the only server-side artifact protecting a vault is the
AES-256-wrapped RSA private key in the user's active EncryptionSuite. Unlock
today is a single client-side path — `deriveAesKey(masterPassword, salt)`
(`src/crypto/aes.js:18`, PBKDF2-SHA256 × 600 000) unwraps that private key,
which is then imported as a non-extractable `CryptoKey`
(`src/store/modules/session.js:59`).

The task is to add a *second, optional* unlock envelope keyed by a WebAuthn
authenticator's PRF output, without changing the master-password path and
without ever giving the server usable key material. The nearest existing
precedent is `emergency-access`, which re-wraps a copy of key material under a
different party's key so a second identity can open the vault
(`src/crypto/emergencyEnvelope.js`, hybrid RSA-OAEP+AES-256-GCM). Passkey login
is the same shape with a different KEK source: instead of a recipient's RSA
public key, the KEK comes from an authenticator-held PRF secret.

Doriath owns its own tables (ADR-001); there is no OpenRegister and no WebAuthn
code exists in the repo yet (`grep -rn navigator.credentials src lib` is empty).

## Goals / Non-Goals

**Goals:**
- Passwordless vault unlock at the lock screen using a platform or roaming
  authenticator, deriving the unlock key from the PRF output entirely client-side.
- The master-password KDF path stays canonical, always offered, and untouched;
  losing the authenticator loses no data.
- Reuse existing envelope + crypto primitives (AES-256-GCM envelope, WebCrypto
  HKDF/AES) — no new cryptographic algorithm, no new job infrastructure.
- Honest feature detection: where PRF is unavailable, the passkey option simply
  does not appear.

**Non-Goals:**
- Passkey login to Nextcloud *identity* (NC core owns that; Doriath only unlocks
  its own vault while an NC session is already active).
- Using the WebAuthn assertion as a server-side authorization gate — under E2E
  the proof of a correct unlock is the successful client-side unwrap, not a
  server signature check.
- Multiple simultaneous unlock keys per suite / key rotation beyond what
  compromise recovery already does.
- Offline passkey unlock (depends on the separate `offline-readonly-cache` change
  for a local envelope copy; out of scope here).

## Decisions

### D1: The PRF output wraps a copy of the vault unlock key, not the private key directly

The "vault unlock key" is the AES-256 key that decrypts the EncryptionSuite's
private-key blob (today derived from the master password via PBKDF2). At
enrollment the browser already holds this key in memory. We derive
`KEK = HKDF-SHA256(prfOutput, salt=credentialId, info="doriath-passkey-kek-v1")`
and store `envelope = AES-256-GCM(KEK, vaultUnlockKey)` using the existing
`encodeEnvelope`/`decodeEnvelope` format (`src/crypto/envelope.js`:
`[version][salt][IV][ciphertext][tag]`, base64). Unlock reverses it, then feeds
the recovered unlock key into the *existing* private-key decryption path — so the
private-key blob on the server is never re-encrypted or duplicated.

*Alternative rejected*: wrapping the imported `CryptoKey` (private key) itself.
Rejected because the private-key `CryptoKey` is `extractable: false` and cannot
be serialized to build an envelope; the unlock key is the correct, already-extant
wrapping target and keeps the private-key blob authoritative in one place.

### D2: PRF obtained via a two-step ceremony, gated on feature detection

Some authenticators only return PRF results on an assertion (`get()`), not on
creation (`create()`). Enrollment therefore does `create()` (to register the
credential) followed immediately by `get()` with
`extensions.prf.eval.first = prfSalt` to obtain the PRF output for the wrap.
A fresh 32-byte `prfSalt` is generated per credential and stored. Before offering
any of this, the client checks `window.PublicKeyCredential` exists and that the
create-time `getClientExtensionResults().prf.enabled === true`; if PRF is not
enabled, enrollment aborts with a clear "your authenticator does not support
passkey unlock" message and the credential is discarded.

*Alternative rejected*: single-step `create()`-only PRF. Rejected for
portability — Windows Hello and several roaming keys surface PRF only on `get()`.

### D3: Endpoints are authenticated (`#[NoAdminRequired]`), owner-scoped, not public

The lock screen runs while the Nextcloud session is valid — only the *vault* is
locked. So passkey endpoints require an NC session and are scoped to the calling
user's own credentials (guarding against IDOR per ADR-005 / hydra-gate-no-admin-idor:
every method loads the credential by id **and** asserts `ownerId === current uid`).
No `#[PublicPage]` and therefore no `public-endpoint-rate-limits` change is
needed. The server issues a WebAuthn challenge and binds the registered
credential id + COSE public key to the user at enrollment for management/anti-
confusion, but the unlock's security rests on the client-side unwrap, not on a
server-side assertion check.

### D4: Staleness on unlock-key change — mark on routine change, delete on rotation

A routine master-password change re-wraps the private key under a new AES key
(encryption-suites: "Master Password Change — Routine"), so every stored passkey
envelope now wraps a dead key. We carry an `unlock_key_epoch` integer that
increments whenever the private-key wrap changes; each envelope records the epoch
it was built for. On unlock, an epoch mismatch marks the envelope `stale` and the
client falls back to master password + prompts re-enrollment. A compromise-recovery
suite rotation (new RSA key pair) invalidates far more, so all passkey envelopes
for that owner are **deleted** outright — the wrapped unlock key can never open
the new suite. This mirrors emergency-access's "re-confirm after the peer rotates"
honesty rather than pretending a dormant blob stays valid forever.

### Declarative-vs-imperative decision

Imperative PHP throughout, per ADR-001 — Doriath owns its tables and does not use
OpenRegister; the passkey credential store is a first-class Doctrine entity
(`doriath_passkey_credentials`) with an `ISchemaWrapper` migration.

### Data model

New table `doriath_passkey_credentials`:

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID | Primary key |
| `owner_id` | string | Nextcloud user id; indexed |
| `credential_id` | text | base64url WebAuthn credential id; unique per owner |
| `public_key` | text | COSE public key (management/anti-confusion; not an E2E gate) |
| `prf_salt` | text | base64 32-byte per-credential PRF input salt |
| `wrapped_unlock_key` | text | AES-256-GCM envelope (KEK-wrapped vault unlock key) |
| `unlock_key_epoch` | int | Epoch of the private-key wrap this envelope targets (see D4) |
| `label` | string | User-facing nickname (e.g. "MacBook Touch ID") |
| `transports` | string | Comma-joined WebAuthn transports (usb, internal, hybrid, nfc) |
| `aaguid` | string | Authenticator model id; nullable |
| `status` | enum | `active`, `stale`, `revoked` |
| `last_used_at` | datetime | Nullable |
| `created_at` | datetime | — |

Composite index on `(owner_id, status)`; unique index on `(owner_id, credential_id)`.

### Unlock flow (prose diagram)

```
Lock screen (NC session valid, vault locked)
  │  feature-detect: PublicKeyCredential present AND passkey enrolled?
  ├─ no  → show master-password field only (unchanged path)
  └─ yes → offer "Unlock with passkey" and "Use master password"

"Unlock with passkey":
  1. GET /api/v1/passkeys/login-options
        → { credentialIds[], prfSalt, wrappedUnlockKey, unlockKeyEpoch, challenge }
  2. navigator.credentials.get({ publicKey: {
        challenge, allowCredentials: credentialIds,
        extensions: { prf: { eval: { first: prfSalt } } } } })
  3. prfOutput = assertion.getClientExtensionResults().prf.results.first
  4. KEK = HKDF-SHA256(prfOutput, salt=credentialId, info="doriath-passkey-kek-v1")
  5. vaultUnlockKey = AES-256-GCM-decrypt(KEK, wrappedUnlockKey)   // fails → 400 / re-enroll
  6. epoch check: server unlockKeyEpoch == envelope epoch? else mark stale → fallback
  7. privateKeyPem = decrypt existing private-key blob with vaultUnlockKey (existing path)
  8. import non-extractable CryptoKey → vault open   // identical end-state to master-pw unlock
```

### Enrollment flow (prose diagram)

```
Settings › Passkeys › "Add passkey"  (vault MUST be unlocked)
  1. GET /api/v1/passkeys/challenge → creation options (rp, user, challenge, prf req)
  2. navigator.credentials.create({ ..., extensions:{ prf:{} } })
        → assert getClientExtensionResults().prf.enabled === true, else abort+discard
  3. navigator.credentials.get(prf.eval.first = freshPrfSalt) → prfOutput
  4. KEK = HKDF(prfOutput, ...); envelope = AES-256-GCM(KEK, vaultUnlockKey-in-memory)
  5. POST /api/v1/passkeys { credentialId, publicKey, prfSalt, wrappedUnlockKey,
        unlockKeyEpoch, label, transports, aaguid }
```

### Browser-support matrix note

PRF (`prf` WebAuthn extension, built on CTAP2 `hmac-secret`) is supported in
Chrome/Edge 116+, Safari 18+ (macOS 15 / iOS 18), and Firefox 135+ with a
compatible platform authenticator (Touch ID, Windows Hello) or roaming key
(YubiKey 5 series). Where the browser lacks `PublicKeyCredential` or the
authenticator reports `prf.enabled !== true`, the passkey option is hidden and
the master-password path is the only one shown. This is feature-detected at
runtime, never assumed from a user-agent string.

### Decisions made under uncertainty

- **U1 — PRF availability is authenticator-specific, not just browser-specific.**
  We cannot know at page load whether the user's authenticator supports PRF, so
  we probe at enrollment (`create()` → `prf.enabled`) and discard the credential
  if unsupported, rather than pre-filtering by UA. Trade-off: one wasted ceremony
  on unsupported hardware, in exchange for correctness.
- **U2 — Whether to also store the COSE public key when the server never verifies
  the assertion.** We store it (cheap) for credential management, duplicate-
  detection, and a possible future hardening where the server *does* verify — but
  we explicitly do NOT gate unlock on it today, to stay honest about where the
  security actually lives (client-side unwrap).
- **U3 — Epoch vs. re-wrap on master-password change.** We chose to *mark stale +
  prompt re-enroll* rather than transparently re-wrapping the passkey envelope
  during a master-password change, because the change flow does not have the PRF
  output available (the authenticator is not necessarily present) and silently
  producing a new envelope is impossible without it. Honest staleness beats a
  fake-valid blob.
- **U4 — Single unlock key wrapped per passkey vs. sharing one envelope across
  passkeys.** We wrap independently per credential (each has its own `prfSalt`
  and envelope) so revoking one passkey never affects another; the small storage
  cost is worth the isolation.

## Risks / Trade-offs

- **PRF secret is authenticator-bound and unexportable** → if the user loses the
  authenticator, that unlock path is gone. Mitigation: master password is always
  the canonical fallback; enrolling ≥2 authenticators is encouraged in the UI.
- **A stolen/compelled unlocked authenticator could unlock the vault** → same
  threat profile as a stolen unlocked laptop with the master password cached;
  mitigation is the existing session-timeout/lock controls plus the ability to
  revoke a passkey server-side, which deletes its envelope immediately.
- **Stale envelope after master-password change silently failing** → mitigated by
  the `unlock_key_epoch` check (D4): mismatched envelopes are marked `stale` and
  the user is told to re-enroll, never left with a mysterious "wrong password".
- **Server storing the wrapped envelope** → the envelope is only openable with the
  authenticator-held PRF secret the server never sees, so at-rest it is exactly as
  safe as the master-password-wrapped private key already is (ADR-003 posture
  preserved).
- **XSS reading the recovered unlock key mid-unlock** → the recovered key is used
  transiently to import a non-extractable `CryptoKey` and then discarded, matching
  the master-password path's existing exposure window; no new class of risk.

## Migration Plan

1. Ship the `doriath_passkey_credentials` migration (additive; no existing table
   touched).
2. Ship backend + frontend behind runtime feature detection — with no enrolled
   passkeys and no PRF support the lock screen is byte-for-byte the current
   master-password screen.
3. Introduce `unlock_key_epoch` starting at 1 for existing suites; the routine
   master-password-change flow increments it (a one-line addition to that flow).
4. **Rollback**: drop the table and remove the passkey UI branch; the
   master-password path is entirely independent and unaffected.

## Open Questions

- Should the app enforce a policy requiring a master password re-entry every N
  days even when passkey unlock is available (defense against a permanently-
  enrolled shared-device authenticator)? Deferred to `org-password-policies`.
- Should an admin be able to disable passkey unlock org-wide (compliance
  environments that mandate a memorized secret)? Likely yes via `IAppConfig`;
  scoped as a follow-up to keep this change focused on the user-facing capability.
