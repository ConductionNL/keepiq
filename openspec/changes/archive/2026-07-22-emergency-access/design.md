# Design: Emergency Access

## Context

Doriath is zero-knowledge end-to-end: the server stores only ciphertext, and every encrypt/decrypt operation happens client-side against the unlocked vault (ADR-003). Any recovery mechanism must preserve that invariant — the server can broker *when* a contact gets access, but never *what* they get without the owner's browser having done the re-wrapping first. This mirrors exactly how `implement-user-sharing` already solves "give another user access to my secret": the owner's browser decrypts, re-encrypts under the recipient's public key, and only the resulting ciphertext is ever POSTed.

## Goals / Non-Goals

- Goal: an emergency contact can eventually gain access to an owner's vault without the owner's real-time cooperation, after a bounded, owner-cancellable wait period.
- Goal: zero server-side plaintext exposure at any step, matching every other Doriath feature.
- Goal: reuse existing primitives (EncryptionSuite public-key wrapping, BackgroundJob-driven expiry, SecretRequest-style notification) rather than inventing new crypto or job infrastructure.
- Non-goal: multi-party quorum grants, enterprise/admin-forced recovery, or recovering access when the owner never designated a contact in the first place (irrecoverable by design — this only helps if configured in advance).

## Decision: Designation happens in two phases (register intent, then confirm-with-rewrap)

Rejected alternative: wrap the key material for the contact at the moment of *designation*. This was rejected because a contact's EncryptionSuite can be revoked/rotated between designation and eventual use (compromise recovery), silently invalidating a dormant wrapped blob with no way for the owner to know. Instead:

1. **Register** (`POST /api/v1/emergency-contacts`): owner names the contact + wait period + level. No crypto yet — just a relationship record (`ownerId`, `contactId`, `waitPeriodHours`, `level`, `status: pending-confirmation`).
2. **Confirm** (`PUT /api/v1/emergency-contacts/{id}/confirm`): owner's browser fetches the contact's *current* active EncryptionSuite public key fresh at confirmation time, decrypts the relevant key material client-side, re-encrypts under that key, and POSTs the ciphertext. Status becomes `active`.

This means an owner must periodically re-confirm if the contact rotates their suite (compromise recovery) — the confirm endpoint is idempotent and safe to re-run, and the owner-side settings panel surfaces "needs re-confirmation" the same way `implement-user-sharing`'s share-sync already surfaces stale shares needing a `PUT .../sync`.

## Decision: Request/wait/grant as a state machine, expiry via BackgroundJob

`EmergencyAccessRequest` states: `requested` → (`rejected` | `granted` | `cancelled`). A new `EmergencyAccessExpiryJob` (registered alongside the existing link-share/secret-request expiry jobs in `lib/BackgroundJob/`) runs on the standard NC cron cadence, transitioning any `requested` row whose `waitPeriodHours` has elapsed since `requestedAt` to `granted`. This is the same shape as the existing auto-delete-on-limit logic for link shares — no new job-scheduling infrastructure needed.

## Decision: `view` vs `takeover` reuse existing authorization seams

- `view`: the contact's `SecretController::index/show` calls gain an additional authorization branch — "does an active, granted EmergencyAccessRequest exist naming me as contact for this owner" — alongside the existing owner-uid check. This is additive to `SecretService`'s existing per-object scoping, not a parallel access-control system (keeps `hydra-gate-no-admin-idor` guard intact: the check is still per-object, just with two valid identities instead of one).
- `takeover`: reuses the exact ownership-transfer code path `secret-export-gdpr`'s design already specs for permanent-delegation-on-deletion — the contact effectively becomes the new owner of record for the secrets in scope, using the same "transfer via existing permanent delegation" mechanism rather than a bespoke one.

## Risks / Trade-offs

- **Stale wrapped key material.** If a contact's suite rotates after confirmation and the owner never re-confirms, the wrapped blob is for a dead key. Mitigated by: the confirm step being idempotent + a settings-panel nudge; documented as an honest limitation, not solved perfectly (same posture as `secret-export-gdpr`'s explicit "server-observable operations only" honesty).
- **Wait-period gaming.** A malicious contact requesting access repeatedly to harass an owner. Mitigated by: every request notifies the owner immediately (so silent grants are impossible) and the owner can reject at any time; rate-limit contact-request submission (ties into the separate `public-endpoint-rate-limits` change's `AnonRateLimit`/`UserRateLimit` pattern, though this endpoint is authenticated so `UserRateLimit` applies).
