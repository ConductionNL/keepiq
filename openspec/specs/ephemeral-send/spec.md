# Ephemeral Send Specification

**Status**: done

**OpenSpec changes:**
- `ephemeral-send` (2026-07-17) — Standalone Bitwarden-Send-style ephemeral sharing: send an ad-hoc typed text/credential via link with burn-after-read (max-view count), optional TTL, and optional password, without creating a vault secret and without the recipient needing an account; includes a "My Sends" management list with revocation and anonymous-endpoint hardening. The standalone delta beyond `link-sharing` (which shares only stored secrets).

## Purpose

@e2e exclude The encryption and burn/expiry lifecycle are client-side and server-side contracts covered by vitest and PHPUnit; the browser-observable flows (create → anonymous open → burned; wrong-password burn; revoke) are covered by the change's Playwright e2e task, not by this evergreen spec.

`link-sharing` shares a **stored** secret via link; there is no way to hand someone a one-off value that is never a vault secret. Ephemeral Send fills that gap: a user types an ad-hoc text or credential, it is encrypted client-side with a random AES-256-GCM key, and a link is produced. The key never reaches the server — it is carried in the URL fragment, or, when the user sets a password, additionally wrapped by an Argon2id-derived key the server never sees. The send burns after a configurable number of views (default 1), optionally expires, and can be revoked. Anyone with the link can read it once without an account. Zero server-side plaintext or decryptable key material (ADR-003).

## Requirements

### Requirement: Create a standalone ephemeral send
The system MUST allow an authenticated user to create an ephemeral send from a typed value without creating any vault `Secret`. The payload MUST be encrypted client-side; the server MUST store only ciphertext and parameters and MUST NOT store plaintext or a key it can use to decrypt.

#### Scenario: Create without a stored secret
- GIVEN an authenticated user types an ad-hoc value
- WHEN they create an ephemeral send
- THEN the system MUST store the ciphertext, a unique ≥128-bit token, and the parameters, and MUST NOT create any vault secret or store the plaintext

#### Scenario: No-password send keeps the key out of the server
- GIVEN a user creates a send without a password
- WHEN it is stored
- THEN the content key MUST NOT be sent to or stored by the server and the server MUST hold only ciphertext it cannot decrypt

### Requirement: Anonymous recipient access with no account
The system MUST allow anyone with the link to retrieve the ciphertext and decrypt it client-side without authenticating, and MUST rate-limit the access endpoints.

#### Scenario: Recipient decrypts a no-password send
- GIVEN a send with no password and remaining views
- WHEN an anonymous recipient opens the link and reads the fragment key
- THEN the system MUST return the ciphertext and the client MUST decrypt it locally, with the server never decrypting the payload

### Requirement: Burn-after-read and optional expiry
The system MUST delete a send once its view count reaches its maximum (default 1) and MUST reject access after an optional TTL. The maximum view count MUST be at least 1 and capped; unlimited is not allowed. After 5 consecutive failed password attempts the send MUST be permanently deleted.

#### Scenario: Send burns at the view limit
- GIVEN a send whose `max_views` is reached
- WHEN the token is accessed again
- THEN the system MUST return an error, return no ciphertext, and have deleted the send

#### Scenario: Brute-force attempts burn the send
- GIVEN a password-protected send
- WHEN 5 consecutive incorrect passwords are submitted
- THEN the system MUST permanently delete the send

### Requirement: Manage and revoke sends
The system MUST let a creator list their own active sends and revoke any before it burns or expires. Only the creator MUST see or revoke their sends.

#### Scenario: Creator revokes a send
- GIVEN an active send owned by the creator
- WHEN they revoke it
- THEN the send MUST be deleted and subsequent access to its token MUST return not-found

## User Stories

- As a user, I want to send a one-off password or connection string via a link so that I don't have to create and later delete a permanent vault secret
- As a user, I want the link to self-destruct after it's read so that the value doesn't linger
- As a user, I want to optionally protect a send with a password for higher-assurance handoffs
- As a recipient without a Nextcloud account, I want to open a link and read the value once
- As a user, I want to see my active sends and revoke one I sent to the wrong person

## Acceptance Criteria

- [ ] A user can send an ad-hoc text/credential via link without creating a vault secret and without the recipient having an account
- [ ] The payload is AES-256-GCM encrypted client-side; the server stores only ciphertext and never plaintext
- [ ] With no password the content key is carried in the URL fragment and never reaches the server; with a password only the Argon2id-wrapped key + salt are stored
- [ ] A send burns after its max-view count (default 1) and rejects access after an optional, capped TTL; unlimited views are not allowed
- [ ] 5 consecutive failed password attempts permanently burn the send
- [ ] Anonymous access endpoints are rate-limited and use ≥128-bit tokens
- [ ] A creator can list and revoke their own sends; no user sees another user's sends
- [ ] The server never holds plaintext or a decryptable content key at any step

## Notes

- Honest scope vs `link-sharing`: link-sharing already covers one-time/usage-limited access, Argon2id snapshot encryption, 5-attempt brute-force burn, expiry, and revocation — for a **stored** secret. Ephemeral Send does not re-invent those; "burn-after-read" is link-sharing's `usage_limit = 1` reused. The genuine delta is the **standalone, no-stored-secret** send, the **optional** password (link-sharing mandates one), the URL-fragment key model, and an independent "My Sends" surface.
- Out of scope for v1: file/attachment sends (see `encrypted-attachments`), promoting a send into a stored secret, editing a send after creation, and unlimited views.
- Related ADRs: ADR-001 (own tables), ADR-003 (encryption architecture — zero server-side plaintext).
