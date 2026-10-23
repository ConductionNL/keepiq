# ADR-003: Keepiq API contract the extension builds against

**Status**: accepted

**Date**: 2026-09-22

Facts verified against the Keepiq app source on 2026-09-22 (branch `development`, app version 0.3.4). Specs and designs in this repo cite this file instead of repeating it. When the server changes, update this file, not the specs.

## Transport and authentication

- Base URL: `https://<host>/index.php/apps/keepiq`. Every route is a plain `index.php` route. There is no `/ocs/v2.php` surface and no `{ocs:{meta,data}}` envelope. Responses are plain JSON.
- Auth: HTTP Basic with the Nextcloud username and a Nextcloud app password. No Keepiq-specific credential exists. Revoking the app password in Nextcloud security settings is the logout-all-devices mechanism.
- Every request carries `OCS-APIRequest: true`. Nextcloud core treats that header as passing the CSRF check for cookie-less requests, which is what lets the OCS-based controllers (secrets, folders, suites, sends) accept an app-password client.
- Send no cookies (`credentials: 'omit'`). A stray Nextcloud session cookie re-arms the strict cookie check and the request fails.
- No controller declares CORS. Requests go from the background script with `host_permissions` for the server origin, never from a content script.
- No server-side session, revision counter, ETag, security stamp, or push exists. The client polls.
- Nextcloud itself provides identity: `GET /ocs/v2.php/cloud/user` (with the same Basic auth and OCS header) returns the display name and email; `GET /index.php/avatar/{uid}/{size}` returns the profile picture.

## Routes the extension uses

All paths relative to the base URL. All require the Basic auth above unless marked public.

| Purpose | Method and path | Notes |
| --- | --- | --- |
| Health | `GET /api/health` | Public. `{status, app, version, checks}`, also with a 503. `version` is the installed Keepiq; the extension needs 0.3.12 or later (`MIN_KEEPIQ_VERSION`, see `keepiq-version` in `ext-share-sync`). A `development` checkout reports an older `-unstable` version, so those always pass. |
| Suites | `GET /api/v1/suites` | Returns a bare JSON array. Pick `status === 'active'`. Row carries `certificate` (X.509 PEM), `privateKey` (AES envelope, base64), `unlockKeyEpoch`. |
| List secrets | `GET /api/v1/secrets` | Query: `folderId`, `search`, `sort` (`name`, `url`, `created_at`, `updated_at`, `last_used_at`), `direction`, `page` (1-based), `limit` (default 50, max 100), `typeId`. Envelope `{items,total,page,limit}`. When `search` is set the other filters are ignored. Sorts end in `name` with no unique tiebreaker, so tied rows can move between pages (keepiq#1234). |
| Full snapshot | `GET /api/v1/offline/manifest` | `{suite, secrets, folders, types, syncedAt}`. Unpaginated. Refused (428) when the admin disabled offline caching, 404 when no active suite. `syncedAt` is a server timestamp, not a cursor. Rows never use the blocked shape here. Secrets stop at 1000 by design (keepiq#1233); a full 1000 means page instead. Rows carry no `migrationError` (keepiq#1235). Under the two-factor policy `suite` is `null` and `unlockBlocked` is set. |
| Secret CRUD | `GET/PUT/DELETE /api/v1/secrets/{id}`, `POST /api/v1/secrets` | Create takes `name` (required), `key` (required ciphertext), `url`, `typeId`, `folderId`, `login`, `additionalFields`; returns 201 with the row. `name` is at most 255 characters. `PUT` is a sparse patch: only fields present in the body change, `null` clears a field. With `baseUpdatedAt` it answers 409 when the stored `updatedAt` differs (whole seconds); without it the last write wins. Since 2026-09-30 `DELETE` moves the secret to the trash (`trashed_at`) and revokes every share of it, returning `{"status":"deleted","trashed":true}`; `POST …/restore` and `DELETE …/purge` restore or purge it, and a daily job purges after the admin's `trash_retention_days` (default 30). Trashed secrets are left out of lists and the manifest. |
| Refusals | any route | The OCS controllers (secrets, folders, the manifest, suites, types) refuse with 428, not 403: Nextcloud turns a 403 there into an HTTP 200 envelope, so `OcsRefusalMiddleware` re-statuses it. The body keeps `message` and adds `error`, a vault policy's code or else `forbidden`. Plain controllers (`DELETE /api/v1/secrets/{id}`, settings) still answer 403, with a policy's `code` when there is one. A blocked suite, a folder someone else owns and a read-only copy all come without a code, so clients cannot tell those apart. The web app treats both statuses alike (`src/utils/refusal.js`). The middleware's comment cites measurements on Nextcloud 35 from 2026-10-04; the manifest's 428 was checked on the stable35 dev server on 2026-10-12. The key-proof guard (`VaultKeyProofMiddleware`) also answers 428, with `error: key_proof_required`, but on none of the routes the extension calls. |
| Folders | `GET/POST /api/v1/folders`, `GET /api/v1/folders/{id}/children`, `PUT/DELETE /api/v1/folders/{id}` | Names are trimmed and refuse `/`; a duplicate sibling is 409. Children: `{directSecretCount, subfolders: [{id, name, secretCount, subfolderCount}]}`. Delete: an empty folder needs nothing, a leaf with items `?cascade=delete|move` (409 without), a folder with subfolders a JSON body `{directSecrets: 'delete'|'move', subfolders: {<id>: 'delete'|'move'|'keep'}}` naming every direct subfolder (409 without, 400 when one is missing). Folder deletes are immediate, with no trash, and count and delete archived and trashed items too. They are all or nothing: a refusal, such as a kept subfolder whose name is taken one level up (409), comes before anything is moved or purged (keepiq#1248). |
| Write context | `GET /api/v1/secrets/{id}/write-context` | `{sourceSecretId, effectiveGrade, ownerCertificate, sourceUpdatedAt}`. `sourceSecretId` equal to `id` means the caller's own item; otherwise `effectiveGrade` `owner` (a delegate), `write` or `manage` may save for every copy, anything else only their own; the extension treats such a copy as view-only and doesn't save it alone. Grades come only from team folders for now; group members get none until keepiq#1285. `ownerCertificate` is `null` when the owner has no active suite. 404 when the secret is gone. |
| Share-save | `PUT /api/v1/secrets/{id}/share-save` | `{baseUpdatedAt, sourceBaseUpdatedAt?, patch, copies, skipped, federated?, suspend?, stage?}`, one transaction. `{id}` is the caller's own row; `sourceBaseUpdatedAt` is required on a copy. `patch` is the sparse body of `PUT /api/v1/secrets/{id}` and must carry `key`, `login` or `additionalFields` (400 `no_value_change`). Copies `{secretId, key?, login?, additionalFields?}` carry exactly the patch's value fields; `skipped` `{secretId, reason: 'no_vault_key'}`; partner shares `{shareId, certFingerprint, key, login, additionalFields}`, the whole value, owner path only; `suspend` `{shareId, reason}`. 200 `{secret, sourceUpdatedAt, copies, federated: {…, ownerOnly}}`. 409 `incomplete` with the required copies (`{secretId, holder, targetUserId}`) and partner shares (`{shareId, recipientCloudId}`), `stale` with `current`, `stale_source`. At most 100 entries per list per call; larger saves send chunks with `stage: {final: false}` (202 `{stageId}`) and commit with `stage: {id, final: true}`; a stage expires after 30 minutes (404 `stage_unknown`). Refusals come as 428. |
| Recipient certificates | `POST /api/v1/shares/recipient-certificates` | `{userIds}`, 1 to 100 distinct (400 outside). `{recipients: [{userId, shareable: true, certificate} \| {userId, shareable: false, reason}]}`. |
| Partner certificate | `POST /api/v1/federation/recipient-certificate` | `{cloudId}` → `{cloudId, certificate, chain, partnerRootFingerprint}`. Plain controller: 404 `unknown_recipient`, 403 `not_a_partner`, 409 `federation_unavailable`, 502 `partner_unreachable` or `bad_chain` (the partner served the wrong chain shape), the reason only as `message`. 60 per minute per user. |
| Types | `GET /api/v1/secret-types` | Types are rows, resolve `typeId` from here. |
| Sends | `POST/GET /api/v1/sends`, `DELETE /api/v1/sends/{id}` | Since 0.1.4, and the path-form recipient link since 0.3.0, so no gate. See payload below. `DELETE` of an unknown or foreign id is a JSON 404. |
| Public send access | `GET /api/v1/public/sends/{token}`, `POST …/access`, `POST …/confirm`, `POST …/failure` | Public, rate limited 15/min. The extension only needs the create side; the web app renders the recipient page. |
| User settings | `GET/PUT /api/settings/user` | `session_timeout` (`session`, `10min`, `30min` or empty for admin default), notification toggles, `default_secret_type`, `offline_cache_optin`. |
| Policy | `GET /api/settings/policy` | Read-only org password policy, a flat object (`SettingsController::getPolicy`, `#[NoAdminRequired]`). The generator reads `policy_enabled` (default false), `generator_min_length` (8 or more, default 12; the extension caps it at 128) and `generator_require_upper`, `_lower`, `_digit` and `_symbol`, all since Keepiq 0.1.4, and `generator_allow_passphrase` (default true) since 0.3.8. Both are older than the minimum, so nothing is gated. |
| Server generator | `POST /api/v1/generate-key` | `{length, includeSpecialCharacters, excludedCharacters, regex}` returns `{generatedKey}`. Not used by the extension: it ports the web app's client-side generator (`src/generator/`), so it generates while locked and offline. |

Error bodies are `{"message": "<text>"}`. Status codes: 400 invalid input, 401 bad credentials, 403 or 428 refused (see Refusals), 404 missing, 423 vault write-locked during a key migration (retry later, do not treat as an auth failure).

Out of reach for an app-password client: suite rotation, revocation and compromise recovery need a vault-key proof signed with the raw private key, and admin force-revoke needs Nextcloud password confirmation. The extension links to the web app for these.

Routes under `/api/v1/extension/*` belong to the in-tree reference extension in the Keepiq repo and are not used by this project.

## Secret shape

`GET /api/v1/secrets/{id}` and list rows:

```json
{ "id": "<uuid>", "name": "GitHub", "url": "https://github.com", "typeId": "<uuid>", "folderId": null,
  "key": "<base64 RSA blob>", "login": "<base64 RSA blob or null>", "additionalFields": "<base64 RSA blob or null>",
  "encryptionSuiteId": "<uuid>", "ownerType": "user", "ownerId": "<uid>", "blocked": false, "useOnly": false,
  "createdAt": "<iso8601>", "updatedAt": "<iso8601>", "keyUpdatedAt": null, "expiresAt": null,
  "possiblyCompromisedAt": null, "tombstonedAt": null, "tombstoneReason": null }
```

- `name`, `url`, `typeId`, `folderId` are plaintext. Search and URL matching work without unlocking.
- `key`, `login`, `additionalFields` are ciphertext. `additionalFields` decrypts to a JSON object of name to value. Names `key`, `login`, `url` are reserved (case-insensitive).
- A recipient's copy of a shared secret carries its limits: `useOnly` (fill only: clients never show or copy `key` or `additionalFields`, and the server refuses its edits), `accessExpiresAt` (access ends then; not `expiresAt`), `readOnly` (from another organisation; only `folderId` may change). A missing limit counts as none. The server cannot stop a client from cloning a restricted copy, so clients refuse that themselves (Keepiq `use-only-shares`, `expiring-shares`).
- Each recipient holds a separate copy encrypted to their own key. A change reaches them only when the editing client re-encrypts it for each and sends it with its own row in one `PUT /api/v1/secrets/{id}/share-save` (see `ext-share-sync`). The server tells partners about a new name or URL itself.
- When the owner's suite is revoked the list returns a row with `blocked: true`, `blockedReason`, and no `key`, `login` or `additionalFields`; clients handle both shapes. `GET /api/v1/secrets/{id}` refuses such a row instead (`SecretService::get`), without a code.
- System types: `login`, `api_key`, `ssh_key`, `certificate`, `note`, `database`, `totp`, `passkey`, `card`, `identity`. Users and admins can add more. Composite types (`totp`, `passkey`, `card`, `identity`) store their payload as JSON in `key`.
- Folders: `{id, name, parentId, ownerType, ownerId, customIcon, customColor, createdAt, updatedAt}`, all plaintext, tree via `parentId`.

## Cryptography (must match the web app byte for byte)

Key hierarchy:

```
master password --PBKDF2-SHA256, 600000 iterations, 16-byte salt--> AES-256-GCM unlock key
unlock key decrypts suite.privateKey envelope --> RSA-4096 PKCS#8 PEM
PEM --importKey(pkcs8, RSA-OAEP SHA-256, extractable: false, ['decrypt'])--> private CryptoKey
suite.certificate --extract SPKI from X.509 DER--> RSA-OAEP public CryptoKey (['encrypt'])
```

Private-key envelope (base64 of):

```
[4 bytes version, big-endian uint32, = 1][16 bytes PBKDF2 salt][12 bytes AES-GCM IV][ciphertext || 16-byte GCM tag]
```

Field ciphertext (base64 of):

```
[4 bytes chunk count, big-endian uint32][512-byte RSA-OAEP block] * count
```

- Plaintext is UTF-8, chunked at 446 bytes. Decrypt all chunks, concatenate the bytes, then decode UTF-8 once. Decoding per chunk tears multi-byte characters.
- An empty string encrypts as one chunk of zero bytes.
- The salt lives inside the envelope, so the KDF parameters needed for unlock are always available offline once the suite row is cached.
- Argon2id (64 MiB, 3 iterations, parallelism 1, 32-byte output, 16-byte salt) is used only for password-protected sends and link shares. The web app uses `argon2-browser` and the extension `hash-wasm`, both WASM with the same parameters; the password is passed as UTF-8.

## Ephemeral send payload

```
POST /api/v1/sends
{ "encryptedPayload": "<base64>", "payloadType": "text" | "credential",
  "maxViews": 1..cap, "ttlSeconds": 3600..2592000, "hasPassword": false }
```

With a password, `"hasPassword": true` adds `"wrappedKey"` and `"argon2idSalt"`; without one both are left out, not null.

- The payload is AES-256-GCM encrypted client-side under a random content key.
- Without a password the content key travels in the URL fragment of the share link and never reaches the server.
- With a password, `wrappedKey` is the content key wrapped under an Argon2id-derived key and `argon2idSalt` is its salt.
- Optional expiry is `ttlSeconds` (0 or absent means none, cap 2592000 = 30 days), stored as `expiresAt`. The extension always sends one of at least an hour.
- `maxViews` cap is 100 (server constant, not exposed by any endpoint). Unlimited views are refused. Five failed password attempts burn the send.
- Row JSON: `id, token, payloadType, hasPassword, maxViews, viewCount, remainingViews, expiresAt, createdAt`.
- Content blob layout is base64 of `[12-byte IV][ciphertext || tag]`; `wrappedKey` uses the same layout with the raw content key as plaintext; `argon2idSalt` is base64 of 16 random bytes.
- Recipient link: `<origin>/index.php/apps/keepiq/public/send/<token>#k=<base64url raw key, unpadded>` when no password; without the fragment when password-protected.
- The "credential" payload type is free text, not structured JSON; the extension sends `Username: …` and `Password: …` on two lines, with English labels. `GET /api/v1/sends` lists the caller's sends, expired ones included until the hourly purge; `DELETE` revokes.
- The recipient link points at the Keepiq web app's public page. The extension does not render it.

## Server-side settings that affect the client

- Admin `default_session_timeout` is one of `session`, `10min`, `30min` and is only a suggested default. The lock is client-enforced; the server has no session to kill.
- Admin `offline_cache_enabled` gates the manifest endpoint with a refusal. Per-user `offline_cache_optin` exists too. When the manifest is unavailable the client falls back to paginated listing.
- Admin `min_password_length` and `min_password_score` apply to master password changes, which the extension does not perform.
