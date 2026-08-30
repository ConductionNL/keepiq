# Design — add-emergency-access

## Context

Doriath is always-E2E (ADR-003): the server holds only ciphertext; the master-password-derived AES key and the decrypted RSA private key live only in the browser (encryption-suites "Session Mechanism"). User sharing already re-encrypts secret material to a recipient's **public certificate** so the recipient can decrypt with their own private key. Link sharing already builds a **hybrid envelope** (random symmetric key encrypts the payload; the symmetric key is protected for the intended opener). Emergency access composes exactly these two primitives to solve the documented "master password lost = data lost" risk without giving the server any new read capability.

## Decisions

### D1 — Recovery escrows the grantor's private key, hybrid-encrypted to the grantee's public certificate
To let a grantee read the grantor's vault, the grantee must be able to decrypt secrets that were encrypted to the grantor's RSA key. The cleanest E2E mechanism is to escrow the grantor's **EncryptionSuite private key** re-encrypted so that only the grantee can open it. A 4096-bit private key exceeds an RSA-OAEP block, so we use a hybrid envelope: a fresh random AES-256-GCM key encrypts the PKCS8 private key, and that AES key is RSA-encrypted to the grantee's public certificate. This is the link-sharing envelope shape applied to a private key, using the user-sharing encrypt-to-recipient primitive. The server stores only this envelope; it is useless to the server and to anyone but the grantee.

### D2 — The envelope is built entirely in the grantor's browser, at designation, while unlocked
At designation the grantor is unlocked: the browser already unwrapped the private key from the master-password-derived AES key this session. To build the envelope it obtains the raw PKCS8 bytes transiently (re-unwrapping the fetched AES-wrapped private-key blob with the session AES key it already holds), generates the random AES key, encrypts, RSA-wraps the AES key to the grantee's public cert, and **discards the raw private-key bytes**. This does not weaken the non-extractable session `CryptoKey` — it re-derives a one-shot copy solely to re-wrap for the grantee, then drops it. The plaintext private key never leaves the browser and is never sent to the server. Re-establishing (after adding a contact or after a key change) repeats this in-browser step.

### D3 — v1 access level is `view`, not takeover
Two models exist in the market: **view** (the emergency contact reads the grantor's vault) and **takeover** (the contact resets the grantor's master password and assumes control). Takeover is more dangerous (it locks the grantor out and hands full control to the grantee) and needs a master-password-reset ceremony. v1 ships **view** only: the grantee recovers the grantor's private key in their own browser and reads the grantor's secrets. Takeover is a deliberately deferred, separately-specced follow-up. This keeps the blast radius small and the crypto identical to sharing.

### D4 — Time delay + grantor veto is the primary control; server release-gating is defence-in-depth
The security of break-glass rests on: (a) a grantor-configured **wait period** during which (b) the grantor can **decline**, plus (c) the fact that only the **grantee's own private key** can open the escrow envelope. The server additionally refuses to release the envelope unless the request is `approved` and the caller is the named grantee — but even a buggy early release would hand the grantee only ciphertext they can already open post-approval, so the release gate is defence-in-depth, not the sole control. The grantor is notified on request (so the veto window is actionable) and on actual access (so misuse is visible).

### D5 — Approval is by timeout, transitioned by a background job (or lazy check), never silently early
A request moves `requested → approved` only when `now >= requested_at + wait_period` and the grantor has not declined. A background job scans for elapsed requests and flips state + audits `emergency_access.approved`; a lazy check on the grantee's fetch attempt is an acceptable equivalent as long as it enforces the same timestamp comparison and records the transition. A decline before the deadline moves `requested → declined` and releases nothing. There is no manual "approve now" in v1 (the grantor's positive action is *not declining*); an explicit early-approve could be an additive follow-up.

### D6 — Envelopes are invalidated on any change to the grantor's key
The escrow holds the grantor's private key as of designation. If the grantor rotates their EncryptionSuite (compromise recovery) or the suite is revoked, existing envelopes hold a stale/void key and MUST be invalidated: a rotation marks envelopes invalid and prompts the grantor to re-establish emergency access (rebuild the envelope against the new key); a revocation clears the envelopes. Listeners on the existing encryption-suites rotation/revocation events drive this. Failing to invalidate would leave a grantee able to recover an old key — acceptable for already-encrypted-with-old-key secrets but misleading for new ones — so we invalidate and force a clean re-establish. Each invalidation audits `emergency_access.invalidated`.

### D7 — Grantee must have an active EncryptionSuite; designation fails loudly otherwise
The envelope is RSA-encrypted to the grantee's public certificate, so a grantee with no active suite cannot be a target — designation MUST fail with a clear error (mirroring user sharing's "recipient has no encryption suite" rule). If a grantee's suite is later revoked, their envelopes are invalidated (D6 symmetry) and they can no longer break glass until re-established against a new suite.

### D8 — No secret material or key material in audit entries
All seven lifecycle events carry only non-sensitive references (grantor id, grantee id, access level, wait period, request state, timestamps). The recovery envelope, private key, and any secret value are NEVER recorded. This is enforced structurally by the existing audit forbidden-key whitelist (`key`, `login`, `password`, `value`, `additionalFields`, `ciphertext`, `payload` are rejected); the new event types add no new metadata keys that could carry key material.

## Risks / Trade-offs

- **Grantee compromise = grantor exposure after the delay.** A trusted-but-compromised emergency contact who lets the timer run gets read access. Mitigations: the grantor picks the contact and the delay, is notified on request and access, can revoke instantly, and can decline within the window. This is the same trust model as Bitwarden emergency access and is inherent to any recovery mechanism.
- **Stale escrow after key change.** Handled by D6 invalidation; the cost is the grantor must re-establish after a rotation.
- **Whole-vault granularity.** v1 grants view of the *entire* grantor vault, not a subset — matching competitors and appropriate for a break-glass path. Per-folder emergency scoping is a possible follow-up.
- **Not a substitute for backups.** Emergency access complements, and does not replace, the encrypted-backup export (`secret-export`) as a recovery path; docs should present both.

## Migration / Rollout

- One migration adds the emergency-contact (and request) storage. No change to existing secret rows. The feature is opt-in per grantor; no existing behaviour changes for users who never designate a contact.
