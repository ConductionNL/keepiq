---
kind: code
---

## Why

Doriath's zero-knowledge design has a documented, high-severity failure mode: **if a user loses their master password, their vault is unrecoverable** (the server holds only ciphertext and never sees the key). The team's own competitive analysis names this in its risk register — *"Master password lost = data lost … Consider emergency access (V1) or admin recovery mechanisms"* (`docs/FEATURES.md`, Risks) — but no emergency-access capability was ever specced or built. Today the only "recovery" is compromise recovery, which requires the user to already be unlocked; a user who is genuinely locked out (or who has died, left the organisation, or been incapacitated) leaves their secrets — and any team credentials only they held — permanently inaccessible.

This is a table-stakes continuity feature in the category: **Bitwarden emergency access**, **Psono emergency codes**, and **1Password's account-recovery for teams** all let a user designate a trusted party who can gain access after a time delay unless the owner intervenes. For Doriath's public-sector and SMB market the gap is acute: an organisation cannot depend on a vault where a single forgotten password destroys business-critical credentials, and Dutch security baselines (BIO, business-continuity expectations) assume a recovery path exists. Emergency access converts "data loss on password loss" from an accepted risk into a governed, auditable, owner-controlled process.

Crucially, emergency access **fits Doriath's existing E2E primitives** rather than weakening them. The vault stays zero-knowledge: recovery works by escrowing the grantor's key material **re-encrypted to a trusted grantee's public certificate**, produced entirely in the grantor's browser — the same "encrypt-to-a-recipient's-public-cert" operation that user sharing already performs. The server never gains the ability to read the vault; it only stores an opaque, grantee-encrypted envelope and enforces the time-delay/veto workflow around releasing it.

## What Changes

Add an **emergency-access** capability: an owner-controlled, time-delayed, auditable break-glass path to a user's vault.

- **Designate emergency contacts.** A grantor (vault owner, unlocked) designates one or more Nextcloud users who have an active EncryptionSuite as emergency contacts, each with a configurable **wait period** (e.g. 1 / 3 / 7 / 30 days; default 7) and access level. **v1 access level is `view`** (the contact can read the grantor's vault); account takeover (resetting the grantor's master password) is a non-goal for v1.
- **Client-side key escrow.** At designation, the grantor's browser builds a **recovery envelope**: the grantor's EncryptionSuite RSA private key, hybrid-encrypted (a fresh random AES-256-GCM key encrypts the private key; that AES key is RSA-encrypted to the grantee's public certificate) — the same envelope shape link-sharing uses, and the same encrypt-to-recipient primitive as user sharing. The raw private-key material exists only transiently in the grantor's browser (unwrapped from the master-password-derived AES key it is already using this session) and is discarded after the envelope is built. The server stores **only** the grantee-encrypted envelope ciphertext — it never sees the private key or a usable key.
- **Break-glass request with a wait timer.** An emergency contact initiates a break-glass request. The server records it, **notifies the grantor**, and starts the grantor's configured wait timer. Nothing is released yet.
- **Grantor veto.** At any time before the timer elapses, the grantor may **decline** the request (and optionally revoke the contact). A declined request releases nothing.
- **Approval by timeout, then view access.** If the wait period elapses with no decline, the request becomes **approved**. The grantee may then fetch the recovery envelope, decrypt it with their **own** in-session private key (their own master password), recover the grantor's private key in their browser, and decrypt and read the grantor's secrets (view). The server releases the envelope only when the request state is `approved`.
- **Revoke.** The grantor may revoke an emergency contact at any time, which deletes the recovery envelope and cancels any pending request; a revoked contact can never break glass.
- **Envelope invalidation on key change.** Because the envelope escrows the grantor's private key as it was at designation, an EncryptionSuite rotation (compromise recovery) or revocation MUST invalidate existing recovery envelopes (they hold the stale key) and prompt the grantor to re-establish emergency access. A revoked suite MUST clear the envelopes.
- **Audit + notifications throughout.** Every lifecycle transition (granted, requested, declined, approved, accessed, revoked, invalidated) is recorded via the existing typed-event audit trail, and the grantor is notified on **request** and on **actual access** via the existing NotificationService. No secret material ever enters an audit entry.

## Capabilities

### Added Capabilities

- `emergency-access`: the full break-glass lifecycle (designation, client-side key escrow, request + wait timer, grantor veto, approval-by-timeout + grantee view access, revocation, key-change invalidation, notifications). A new capability is the correct home — it is a peer of `link-sharing`, `secret-requests`, and `user-sharing`, reuses their encrypt-to-recipient / hybrid-envelope primitives, but is a distinct lifecycle with its own entity, endpoints, timer, and audit events.

### Modified Capabilities

- `secret-audit-trail`: MODIFIES the "Server-Observable Operation Capture" requirement to add the emergency-access lifecycle events (`emergency_access.granted`, `.requested`, `.declined`, `.approved`, `.accessed`, `.revoked`, `.invalidated`) to the audited-operation catalogue, with the grantor or the grantee as actor as appropriate. No secret material is recorded (the existing forbidden-key whitelist already guarantees this).

## Impact

- **Database**: a new `doriath_emergency_contact` table (grantor id, grantee id, access level, wait-period days, state, timestamps, and the grantee-encrypted recovery-envelope blob) and a companion request state (either on the same row or a small `doriath_emergency_request` table) — one migration. No change to `doriath_secret`.
- **Backend**: an `EmergencyAccessService` + controller for the lifecycle; a background job (or timestamp comparison on read) to transition `requested → approved` when the wait period elapses; new `AuditEventTypes` constants and new `NotificationService` subjects; listeners so suite rotation/revocation invalidates/clears envelopes.
- **Frontend**: user-settings UI to designate/revoke emergency contacts and set wait periods; a "request emergency access" flow for a grantee; a grantor decline action from the notification; a read-only grantee view of the grantor's vault after approval (decrypting via the recovered private key in-session).
- **API**: new routes for designate / revoke / request / decline / fetch-envelope, all session-authenticated; the fetch-envelope route MUST refuse unless the request is `approved` and the caller is the grantee.
- **Cross-capability**: reuses the user-sharing encrypt-to-recipient-public-cert primitive and the link-sharing hybrid-envelope shape; hooks encryption-suites rotation/revocation for invalidation; extends secret-audit-trail with new event types.
- **Security**: the vault stays zero-knowledge — the server stores only a grantee-encrypted envelope and never a usable key; the real controls are the grantor-configured time delay, the grantor veto, and the fact that only the grantee's own private key can open the envelope. The grantor is notified on request and on access. Envelopes are invalidated on key change so a stale escrow cannot silently outlive a rotation. No secret material or key material enters an audit entry.
