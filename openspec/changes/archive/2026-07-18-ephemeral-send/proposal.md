---
kind: code
---

# Proposal: Ephemeral Send (standalone ad-hoc encrypted shares)

## Why

Doriath can share a **stored** secret via a link (`link-sharing`) and can request a secret be filled in (`secret-requests`), but it has **no way to send an ad-hoc piece of text or a credential that is not — and never becomes — a vault secret**. Every link share is bound to an existing `Secret`: `LinkShareService::create` requires a `secretId` (`lib/Service/LinkShareService.php:119`, guard at `:128`) and the `LinkShare` entity's first field is `secretId` (`lib/Db/LinkShare.php:65`). To hand someone a one-off value (a temporary Wi-Fi password, a connection string a colleague needs once, a recovery code) a user must first create a permanent vault secret, snapshot-share it, then delete it — friction that pushes users to paste secrets into chat instead.

This is a standard, expected feature of the category:

- **Bitwarden Send** — ephemeral sharing is a free-tier feature users expect (`docs/FEATURES.md:24`, `:308`).
- **Vaultwarden** ships the same Send surface (`docs/FEATURES.md:25`).
- On the Nextcloud platform specifically, the entire **Nextcloud Secrets** app (theCalcaholic, AGPL) exists *solely* to create one-time, end-to-end-encrypted share links for ad-hoc text — direct proof of standalone demand for exactly this on NC, independent of any vault.

Doriath already has the two adjacent building blocks (`link-sharing`'s snapshot encryption + brute-force/auto-delete lifecycle, and `secret-requests`' anonymous token endpoints), so the remaining gap is narrow and specific: **the no-stored-secret send**.

## What Changes

**Honest scope narrowing (overlap with `link-sharing`).** `link-sharing` already covers, for a *stored* secret: one-time / usage-limited access (`usage_limit` min 1, default 1 — `openspec/specs/link-sharing/spec.md:27`), Argon2id-derived snapshot encryption (`:74`), 5-attempt brute-force auto-delete (`:83`), optional expiry (`:30`), and manual revocation (`:110`). This change does **not** re-invent those. "Burn-after-read" is not a new primitive — it is `link-sharing`'s `usage_limit = 1`, reused. The genuine delta is only what `link-sharing` structurally cannot do because it is bound to a `Secret`:

- **Standalone ad-hoc send** — a new `EphemeralSend` object holding an encrypted, user-typed text/credential payload with **no `secret_id`, no vault `Secret` created, ever**. This is the whole point; `link-sharing`'s `secretId`-required create path cannot express it.
- **Optional password** — `link-sharing` *mandates* a password (its AES key is Argon2id-derived from it, `openspec/specs/link-sharing/spec.md:32`). Ephemeral Send makes the password **optional**: with no password, the client generates a random AES-256 key and carries it in the URL **fragment** (`#k=…`), which browsers never transmit to the server (the Nextcloud Secrets / Bitwarden Send model) — so the server holds ciphertext it cannot decrypt. With a password, an Argon2id layer additionally wraps that key, exactly as `link-sharing` derives keys today.
- **Independent "Sends" management surface** — `link-sharing`'s list and revocation are per-secret (`/api/v1/secrets/{secretId}/link-shares`, `appinfo/routes.php:82`). Ephemeral sends are not attached to any secret, so they need their own "My Sends" list + revoke, owned by the creating user.
- **Anonymous-recipient hardening** — public fetch/access endpoints (mirroring `LinkShareAccessController`'s `#[PublicPage]` pattern, `lib/Controller/LinkShareAccessController.php:72`) carry `#[AnonRateLimit]` (per the `public-endpoint-rate-limits` change's convention), 128-bit token entropy (as `secret-requests` `openspec/specs/secret-requests/spec.md:26`), and the same 5-attempt brute-force burn as link shares.

Concretely:

- Add an **`EphemeralSend`**: `{ id, owner_id, token, encrypted_payload, payload_type (text|credential), has_password, max_views (min 1, default 1), view_count, expires_at (optional, capped), failed_attempts, created_at }`. No key material and no plaintext are ever stored — the AES key lives only in the recipient URL fragment and (optionally) behind an Argon2id password the server never sees.
- **Create** (authenticated): the creator's browser encrypts the typed payload with a fresh random AES-256 key, optionally wraps the key with an Argon2id-derived key from a chosen password, and POSTs only the ciphertext + parameters. The server returns the token; the client assembles the shareable URL, appending the raw key to the fragment when no password is set.
- **Access** (anonymous, no account): recipient opens the link; the browser fetches ciphertext + metadata by token, derives/reads the key (password prompt or URL fragment), decrypts client-side, and the server increments `view_count` — **burning the send when `view_count` reaches `max_views`** (reusing `link-sharing`'s auto-delete-at-limit lifecycle) and burning after 5 failed password attempts.
- **Manage**: "My Sends" list + one-click revoke for the creator.
- **Explicitly out of scope for v1**: file/attachment sends (covered separately by `encrypted-attachments`), promoting a send into a stored vault secret, editing a send after creation (revoke + recreate), and unlimited views (min 1, capped max — consistent with `link-sharing`).

## Capabilities

### New Capabilities
- `ephemeral-send`: Standalone Bitwarden-Send-style ephemeral sharing — send an ad-hoc user-typed text/credential to anyone via link, with burn-after-read (max-view count), optional TTL, and optional password, without creating a vault secret and without the recipient needing an account. Includes a "My Sends" management list with revocation and anonymous-endpoint hardening.

### Modified Capabilities
<!-- No existing capability's REQUIREMENTS change. link-sharing stays bound to stored secrets; ephemeral-send is a parallel, standalone object reusing link-sharing's lifecycle *pattern* (Argon2id, 5-attempt burn, auto-delete-at-limit) as design precedent, not by modifying its spec. -->

## Impact

- **New DB table**: `doriath_ephemeral_sends` (own table, ADR-001). No change to any existing table; no `Secret` rows involved.
- **New service**: `EphemeralSendService` (create/list/revoke/access/burn) — no dependency on `SecretService`/`ShareService` (sends are not secrets).
- **New controllers + routes**: `EphemeralSendController` (authenticated CRUD) and `EphemeralSendAccessController` (`#[PublicPage]` + `#[AnonRateLimit]` fetch/access), registered under a commented "Ephemeral send" section in `appinfo/routes.php`.
- **New background job**: expire/purge job for TTL-elapsed and fully-burned sends (mirrors the link-share auto-delete lifecycle).
- **Frontend**: "New Send" modal (type payload, choose views/TTL/optional password, copy link) and a "My Sends" list; Vue 2 + WebCrypto (AES-256-GCM + Argon2id), URL-fragment key handling.
- **OpenConnector**: unaffected — ephemeral sends are a human ad-hoc feature; the machine `doriath://` path is untouched.
- **Security**: zero server-side plaintext — the AES key never reaches the server (URL fragment) and, when a password is set, is additionally Argon2id-wrapped client-side (ADR-003 preserved). Anonymous endpoints are rate-limited and brute-force-burned.
