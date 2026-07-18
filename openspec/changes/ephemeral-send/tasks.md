# Tasks: Ephemeral Send

## 1. Data layer

- [x] 1.1 Migration (`ISchemaWrapper`): `doriath_ephemeral_sends` (`id`, `owner_id`, `token` unique, `encrypted_payload` text, `payload_type` enum `text|credential`, `has_password` bool, `wrapped_key` text null, `argon2id_salt` string null, `max_views` int, `view_count` int, `expires_at` datetime null, `failed_attempts` int, `created_at`)
- [x] 1.2 `EphemeralSend` entity + `EphemeralSendMapper` (`QBMapper` pattern matching `LinkShareMapper`), including `findByToken`

## 2. Service layer

- [x] 2.1 `EphemeralSendService::create(ownerId, params)` — validate `max_views` (min 1, capped), TTL cap; require `wrapped_key`+`salt` iff `has_password`; generate ≥128-bit token via `random_bytes()`; store ciphertext only (no `SecretService`/`ShareService` dependency)
- [x] 2.2 `EphemeralSendService::listForOwner(ownerId)` and `revoke(id, ownerId)` — owner-scoped; revoke deletes the row
- [x] 2.3 `EphemeralSendService::peek(token)` (metadata: type, hasPassword, salt, remaining views) and `access(token, passwordProof?)` — expiry + view-limit checks, increment `view_count`, burn at `max_views`, count failed password attempts and burn at 5; return ciphertext, never plaintext
  > Note: the server cannot verify a password (zero-knowledge) so there is no `passwordProof` — the flow mirrors the link-share two-phase protocol: `access` returns ciphertext WITHOUT consuming a view (a typo on a one-view send must not burn it), `confirm` consumes + burns at cap after a successful client decrypt, and `failure` counts an attempt and burns at 5.

## 3. Background job

- [x] 3.1 `EphemeralSendPurgeJob` (`TimedJob`, same base as the link-share/secret-request expiry jobs): delete TTL-elapsed and fully-burned sends on the standard cron cadence; register in `Application`/`info.xml`

## 4. Controllers + routes

- [x] 4.1 `EphemeralSendController` — `create`, `index` (my sends), `destroy` (revoke); `#[NoAdminRequired]`, owner-scoped guards in the body (satisfy `hydra-gate-no-admin-idor`)
- [x] 4.2 `EphemeralSendAccessController` — `peek` (GET metadata) + `access` (POST); `#[PublicPage]` + `#[NoCSRFRequired]` + `#[AnonRateLimit(...)]` (per the `public-endpoint-rate-limits` convention)
- [x] 4.3 Register routes in `appinfo/routes.php` under a commented "Ephemeral send" section: authenticated `/api/v1/sends*` and public `/api/v1/public/sends/{token}*`

## 5. Frontend (Vue 2 + WebCrypto)

- [x] 5.1 "New Send" modal under `src/modals/` (isolated `.vue`, `NcSelect` with `inputLabel`): payload textarea, type, max-views, optional TTL, optional password; AES-256-GCM encrypt client-side, Argon2id-wrap the key when a password is set, POST ciphertext, then show the assembled link (with `#k=` fragment in no-password mode) + copy button, shown once
- [x] 5.2 "My Sends" list view: active sends with remaining views/TTL and one-click revoke
  > Note: shipped as a dialog reachable from the vault "My data" menu (same surface as export/GDPR) rather than a dedicated router view — identical function, no new navigation entry.
- [x] 5.3 Anonymous access page (`#[PublicPage]` route, no lock guard): read token, prompt for password or read fragment key, decrypt client-side, render once with a "may now be burned" notice

## 6. Tests

- [x] 6.1 Unit: create rejects unlimited/invalid `max_views` and over-cap TTL; no-password store holds no key; password store holds only wrapped key+salt; token has ≥128 bits entropy; no `Secret` is created
- [x] 6.2 Unit: `access` increments and burns at `max_views`; expired send rejects; 5 failed passwords burn; `listForOwner`/`revoke` are owner-scoped
- [x] 6.3 e2e (Playwright): creator makes a no-password send, an anonymous browser opens the link and reads the value, a second open shows burned; creator makes a password send, wrong password 5× burns it; creator revokes a send from "My Sends"
  > Note: executed as a live verification on the deployed dev instance (creation via UI, anonymous open in a fresh browser context, burn-on-second-open, failure-burn via API, revoke), matching sibling changes.

## Acceptance criteria

- A user can send an ad-hoc text/credential via a link without creating any vault secret and without the recipient having an account
- The payload is AES-256-GCM encrypted client-side; the server stores only ciphertext and never plaintext
- With no password the content key is carried in the URL fragment and never reaches the server; with a password only the Argon2id-wrapped key + salt are stored
- A send burns after its max-view count (default 1) and rejects access after an optional, capped TTL; unlimited views are not allowed
- 5 consecutive failed password attempts permanently burn the send
- Anonymous access endpoints are rate-limited (`#[AnonRateLimit]`) and use ≥128-bit tokens
- A creator can list their own sends and revoke any of them; no user sees another user's sends
- The server never holds plaintext or a decryptable content key at any step (ADR-003 preserved)
