# Design: Ephemeral Send

## Context

Doriath's sharing surfaces are all bound to a stored `Secret`: `link-sharing` snapshots an existing secret (`LinkShareService::create` requires a `secretId`, `lib/Service/LinkShareService.php:119`; `LinkShare.secretId`, `lib/Db/LinkShare.php:65`) and `secret-requests` writes into a placeholder `Secret`. There is no way to send a one-off value that is never a vault secret. This design adds a **standalone** `EphemeralSend` object — an encrypted, user-typed payload with its own token, lifecycle, and no `Secret` involvement.

The immovable constraint is ADR-003: the server never holds plaintext or decryptable key material. `link-sharing` satisfies this by deriving the AES key from a mandatory Argon2id password the server never sees (`openspec/specs/link-sharing/spec.md:32`). Ephemeral Send generalizes it so a password is **optional**, using the URL-fragment-key model that the Nextcloud Secrets app and Bitwarden Send use.

## Goals / Non-Goals

**Goals:**
- Send an ad-hoc text/credential to anyone via link, no vault secret created, no recipient account.
- Burn-after-read (max-view count, default 1), optional TTL, optional password.
- Reuse `link-sharing`'s proven lifecycle *pattern* (Argon2id, 5-attempt brute-force burn, auto-delete-at-limit) rather than inventing new crypto/job infrastructure.
- A "My Sends" list with revocation, owned by the creator.
- Zero server-side plaintext or decryptable key material (ADR-003).

**Non-Goals:**
- Re-implementing anything `link-sharing` already does for stored secrets (this is a parallel object, not a modification).
- File/attachment sends (`encrypted-attachments` covers files).
- Promoting a send into a stored secret, or editing a send after creation (revoke + recreate).
- Unlimited views (min 1, capped max, consistent with `link-sharing`).

## Decisions

### Decision: The AES key never reaches the server — URL fragment, optionally Argon2id-wrapped

The client generates a random **AES-256-GCM** content key, encrypts the typed payload with it, and POSTs only the ciphertext. Key delivery to the recipient depends on the password choice:

- **No password**: the raw content key is base64url-encoded into the shareable URL's **fragment** (`…/send/{token}#k=<key>`). Browsers never transmit the fragment in an HTTP request, so the server receives the token but never the key — it stores ciphertext it structurally cannot decrypt.
- **With password**: the content key is wrapped by an AES key derived from the chosen password via **Argon2id** (identical KDF to `link-sharing`, `openspec/specs/link-sharing/spec.md:74`); the wrapped key + salt are stored, the fragment is not used, and the recipient must enter the password to unwrap client-side.

*Rejected alternative:* always require a password (the `link-sharing` model). Rejected because the whole ergonomic point of a Send is a single copy-paste link; the fragment-key model preserves zero-knowledge without a second out-of-band secret. The password remains available for higher assurance.

### Decision: Own table (ADR-001), no `Secret`, no share machinery

Per ADR-001 (own Doctrine entities, no OpenRegister). New table, no existing table touched:

**`doriath_ephemeral_sends`**

| Column | Type | Notes |
|--------|------|-------|
| `id` | string (UUID) | PK |
| `owner_id` | string | NC user id of the creator (sole manager) |
| `token` | string | URL-safe random, ≥128 bits entropy via `random_bytes()` (as `secret-requests`, `openspec/specs/secret-requests/spec.md:26`) |
| `encrypted_payload` | text | AES-256-GCM ciphertext of the typed value; server never decrypts |
| `payload_type` | enum `text`\|`credential` | UI hint only |
| `has_password` | bool | true → `wrapped_key` + `argon2id_salt` populated; false → key is in the recipient's URL fragment |
| `wrapped_key` | text nullable | Argon2id-wrapped content key (password mode only) |
| `argon2id_salt` | string nullable | base64 salt (password mode only) |
| `max_views` | int | min 1, default 1, capped (e.g. 100); unlimited not allowed |
| `view_count` | int | incremented per successful decrypt-eligible fetch |
| `expires_at` | datetime nullable | optional TTL, capped (e.g. ≤30 days) |
| `failed_attempts` | int | password attempts; 5 → burn |
| `created_at` | datetime | |

**No plaintext and no unwrapped key are ever stored.** In no-password mode nothing decryptable is stored at all; in password mode only the Argon2id-wrapped key + salt (server can't unwrap without the password). `EphemeralSendService` has no `SecretService`/`ShareService` dependency — a send is not a secret.

### Decision: Burn lifecycle reuses link-sharing's auto-delete-at-limit shape

`view_count` increments on each access that returns ciphertext; when it reaches `max_views` the row is deleted (burn-after-read = `max_views` default 1 — the same semantics as `link-sharing`'s `usage_limit`, not a new primitive). 5 consecutive failed password attempts delete the row (as `link-sharing` `:83`). A `TimedJob` purges TTL-elapsed and already-burned rows on the standard NC cron cadence, mirroring the link-share expiry job.

### Decision: Anonymous access is hardened at the endpoint

The public fetch/access endpoints mirror `LinkShareAccessController`'s `#[PublicPage]` pattern (`lib/Controller/LinkShareAccessController.php:72`) and additionally carry `#[AnonRateLimit(limit: …, period: 60)]` per the `public-endpoint-rate-limits` change's convention (`openspec/changes/public-endpoint-rate-limits/proposal.md:19`). Token entropy (≥128 bits) makes enumeration infeasible; the 5-attempt burn defeats password guessing; rate-limiting caps anonymous request volume.

### Declarative-vs-imperative decision

Doriath has no OpenRegister — everything is imperative PHP by ADR-001. `EphemeralSend` is an own Doctrine entity with a `QBMapper`; create/list/revoke/access/burn are `EphemeralSendService` methods; there is no declarative schema/register layer.

## API endpoints

Authenticated (`#[NoAdminRequired]`, owner-scoped in the body):
- `POST   /api/v1/sends` — create `{ encryptedPayload, payloadType, hasPassword, wrappedKey?, argon2idSalt?, maxViews?, expiresAt? }` → `{ token }`.
- `GET    /api/v1/sends` — the creator's "My Sends" list (metadata only, never ciphertext of others).
- `DELETE /api/v1/sends/{id}` — revoke (delete) a send the caller owns.

Anonymous (`#[PublicPage]` + `#[AnonRateLimit]`):
- `GET  /api/v1/public/sends/{token}` — metadata: `{ payloadType, hasPassword, argon2idSalt?, remainingViews }` (no ciphertext yet; lets the client decide to prompt for a password).
- `POST /api/v1/public/sends/{token}/access` — `{ passwordProof? }` for password mode; returns `{ encryptedPayload }`, increments `view_count`, burns at `max_views`; counts/burns on failed password attempts. No password mode returns ciphertext directly (key is client-side in the fragment).

## Frontend surfaces (Vue 2 + WebCrypto)

- **"New Send" modal** (own `.vue` under `src/modals/`, `hydra-gate-modal-isolation`): textarea for the payload, `payloadType` select (`NcSelect` with `inputLabel`), max-views + optional TTL + optional password fields; on submit the client AES-256-GCM-encrypts, optionally Argon2id-wraps the key, POSTs ciphertext, then shows the assembled link (with `#k=` fragment when no password) and a copy button — shown once.
- **"My Sends" list**: the creator's active sends with remaining views, TTL, and a one-click revoke.
- **Anonymous access page** (`#[PublicPage]` route, no lock guard): reads the token, prompts for a password or reads the fragment key, decrypts client-side, renders the value once, and shows a "this link may now be burned" notice.

## Risks / Trade-offs

- **Fragment key in browser history / referrer.** The `#k=` key can persist in local history. Mitigated by: the send self-burning after `max_views` (default 1), the standard practice for this model, and offering the password mode for higher assurance. Documented honestly, matching the Nextcloud Secrets / Bitwarden Send posture.
- **No delivery confirmation.** A burned view could be an attacker who intercepted the link rather than the intended recipient. Mitigated by short TTLs, `max_views = 1`, and the optional password. Inherent to link-based sending; same limitation as `link-sharing`.
- **Anonymous abuse of the create surface is impossible** — creation is authenticated; only *access* is anonymous, and it is rate-limited + brute-force-burned.
- **Payload size.** AES-GCM has no ~500-byte RSA chunking limit (ADR-003 `:41` is RSA-only), so large text is fine; a sane server-side max on `encrypted_payload` prevents abuse.

## Decisions made under uncertainty

1. **Optional password with a URL-fragment key by default** — chosen so a Send is a single copy-paste link (the category norm) while preserving zero-knowledge; assumes the fragment-never-sent browser guarantee, which is universal.
2. **Standalone own table, no `Secret` and no share machinery** — chosen because a send is explicitly *not* a vault secret; reusing `Secret` would drag in RSA suites, sync, and folders that make no sense here.
3. **Burn-after-read is `max_views` default 1, reusing link-sharing's limit semantics** — chosen to avoid inventing a second lifecycle; honestly a rename, not a new primitive.
4. **Capped `max_views` and capped TTL, no unlimited** — chosen for consistency with `link-sharing` and to keep an ephemeral send genuinely ephemeral.
5. **AES-256-GCM for the payload (not RSA)** — chosen because there is no per-recipient public-key dimension (any holder of the link decrypts); GCM also authenticates, and there is no RSA chunking limit to manage.
6. **Anonymous endpoints get `#[AnonRateLimit]` + 5-attempt burn** — chosen to harden the one anonymous surface; ties into the fleet `public-endpoint-rate-limits` convention rather than a bespoke limiter.
7. **File sends deferred to `encrypted-attachments`** — chosen to keep this change to the text/credential ad-hoc case; attachments have their own encryption/size concerns.
