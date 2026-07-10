---
kind: code
---

# Proposal: Emergency access for lost master passwords

## Why

`docs/FEATURES.md:359` names Doriath's own top risk in its risk table:

> Master password lost = data lost | High | This is by design (zero-knowledge). Document clearly. Consider emergency access (V1) or admin recovery mechanisms (Enterprise).

Nothing implements the "consider emergency access (V1)" mitigation — verified: no `openspec/changes/*` (active or archived) mentions emergency access, and no `Emergency` symbol exists anywhere in `lib/` or `src/` (`grep -ril emergency lib src` returns nothing). The competitor comparison table in the same file (`docs/FEATURES.md:24`) lists "emergency access" as a Bitwarden feature Doriath does not have; Psono's row (`docs/FEATURES.md:28`) lists "emergency codes" likewise.

Doriath's zero-knowledge architecture (ADR-003, `openspec/architecture/adr-003-rsa-aes-encryption-architecture.md`) means the server can never reset a forgotten master password — that constraint is permanent and correct. But it also means the *only* mitigation that can exist is one designed the same way password-health and secret-export-gdpr were designed: entirely client-side key material handling, with the server acting only as a relay for encrypted blobs it can never read.

The established fleet pattern for exactly this "recover access when the primary key holder is unavailable" problem is Bitwarden's Emergency Access: the vault owner names a trusted emergency contact ahead of time; the contact can *request* access; after a configurable waiting period (during which the owner can reject/cancel), the contact is granted a time-boxed, revocable view (or takeover) of the vault. The cryptography is: the owner's vault key is wrapped a second time under the contact's public key at grant time (not at request time) — precisely mirroring the RSA-per-recipient re-wrapping Doriath's `implement-user-sharing` spec already implements for ordinary secret sharing (the "sync" mechanism keeping the recipient's encrypted copy current).

This is squarely a **V1**-tier gap in Doriath's own roadmap language, has the highest severity rating in the app's own risk table, and — unlike most remaining gaps — has no existing OpenSpec change covering it (checked all 6 active changes' `proposal.md` "Why"/"What Changes" sections: none mention emergency contacts, trusted takeover, or recovery-by-delegate).

## What Changes

- Implement **emergency contact designation**: a vault owner names one or more trusted Doriost users as emergency contacts, each with a configured **wait period** (e.g. 24h/48h/7d) and an access **level** (`view` — contact can read secrets after grant; `takeover` — contact effectively becomes co-owner). Designation requires the owner's vault to be unlocked (their existing EncryptionSuite public key is what gets used for wrapping).
- Implement the **grant handshake**: designating a contact does NOT yet share any data. It creates a pending emergency-access record. The owner must separately confirm the grant (mirroring how `implement-user-sharing`'s share-request flow works) — at confirmation time, the owner's browser re-wraps the relevant EncryptionSuite-derived key material under the contact's own active suite public key and POSTs the resulting ciphertext; the server never sees plaintext key material at any step.
- Implement the **request-and-wait flow**: an emergency contact who believes the owner is unavailable submits an access request. The server starts the configured wait-period timer and notifies the owner (NC notification + email, mirroring `SecretRequest`'s notification pattern). The owner can **reject** the request at any point during the wait period, immediately invalidating it.
- Implement **auto-grant on wait-period expiry**: a background job (mirroring the existing `BackgroundJob` pattern already used for link-share auto-deletion and secret-request expiry) checks pending emergency-access requests; once the wait period elapses without an owner rejection, the request transitions to `granted` and the contact gains access per their configured level — `view` (list/read the owner's secrets, decrypted client-side using the key material wrapped for them at designation time) or `takeover` (in addition, the contact can now designate themself an EncryptionSuite as if they were the owner, per the existing `compromiseRecovery` flow's ownership-transfer semantics).
- Implement **owner-side visibility and control**: a settings panel (Doriath admin/user settings, per `implement-dashboard-settings` conventions) listing configured emergency contacts, pending requests, and a one-click "reject and revoke" action.
- Implement **typed audit events** (`EmergencyAccessRequestedEvent`, `EmergencyAccessRejectedEvent`, `EmergencyAccessGrantedEvent`) consumed by the (currently unimplemented but fully specced) `add-secret-audit-trail` change — this change's events slot into that pipeline the same way `secret-export-gdpr`'s events were designed to.
- Explicitly **out of scope for v1**: multi-contact quorum/threshold grants (e.g. "2 of 3 contacts must agree"), organization/enterprise admin recovery (FEATURES.md's separate "Enterprise" tier suggestion) — this change covers only the single-owner, single-or-multiple-independent-contact case.
