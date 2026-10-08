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
| Suites | `GET /api/v1/suites` | Returns a bare JSON array. Pick `status === 'active'`. Row carries `certificate` (X.509 PEM), `privateKey` (AES envelope, base64), `unlockKeyEpoch`. |
| List secrets | `GET /api/v1/secrets` | Query: `folderId`, `search`, `sort` (`name`, `url`, `created_at`, `updated_at`), `direction`, `page` (1-based), `limit` (default 50, max 100), `typeId`. Envelope `{items,total,page,limit}`. When `search` is set the other filters are ignored. |
| Full snapshot | `GET /api/v1/offline/manifest` | `{suite, secrets, folders, types, syncedAt}`. Unpaginated. 403 when the admin disabled offline caching, 404 when no active suite. `syncedAt` is a server timestamp, not a cursor. Rows never use the blocked shape here. |
| Secret CRUD | `GET/PUT/DELETE /api/v1/secrets/{id}`, `POST /api/v1/secrets` | Create takes `name` (required), `key` (required ciphertext), `url`, `typeId`, `folderId`, `login`, `additionalFields`; returns 201 with the row. `PUT` is a sparse patch: only fields present in the body change, `null` clears a field. Last write wins. Delete returns `{"status":"deleted"}`. |
| Folders | `GET/POST /api/v1/folders`, `GET /api/v1/folders/{id}/children`, `PUT/DELETE /api/v1/folders/{id}` | Delete takes `?cascade=delete|move`. |
| Types | `GET /api/v1/secret-types` | Types are rows, resolve `typeId` from here. |
| Sends | `POST/GET /api/v1/sends`, `DELETE /api/v1/sends/{id}` | See payload below. |
| Public send access | `GET /api/v1/public/sends/{token}`, `POST …/access`, `POST …/confirm`, `POST …/failure` | Public, rate limited 15/min. The extension only needs the create side; the web app renders the recipient page. |
| User settings | `GET/PUT /api/settings/user` | `session_timeout` (`session`, `10min`, `30min` or empty for admin default), notification toggles, `default_secret_type`, `offline_cache_optin`. |
| Policy | `GET /api/settings/policy` | Read-only org password policy. |
| Server generator | `POST /api/v1/generate-key` | `{length, includeSpecialCharacters, excludedCharacters, regex}` returns `{generatedKey}`. Not used by the extension; generation is client-side (ADR-001, Bitwarden parity, offline). |

Error bodies are `{"message": "<text>"}`. Status codes: 400 invalid input, 401 bad credentials, 403 forbidden or suite blocked, 404 missing, 423 vault write-locked during a key migration (retry later, do not treat as an auth failure).

Out of reach for an app-password client: suite rotation, revocation and compromise recovery need a vault-key proof signed with the raw private key, and admin force-revoke needs Nextcloud password confirmation. The extension links to the web app for these.

Routes under `/api/v1/extension/*` belong to the in-tree reference extension in the Keepiq repo and are not used by this project.

## Secret shape

`GET /api/v1/secrets/{id}` and list rows:

```json
{ "id": "<uuid>", "name": "GitHub", "url": "https://github.com", "typeId": "<uuid>", "folderId": null,
  "key": "<base64 RSA blob>", "login": "<base64 RSA blob or null>", "additionalFields": "<base64 RSA blob or null>",
  "encryptionSuiteId": "<uuid>", "ownerType": "user", "ownerId": "<uid>", "blocked": false,
  "createdAt": "<iso8601>", "updatedAt": "<iso8601>", "keyUpdatedAt": null, "expiresAt": null,
  "possiblyCompromisedAt": null, "tombstonedAt": null, "tombstoneReason": null }
```

- `name`, `url`, `typeId`, `folderId` are plaintext. Search and URL matching work without unlocking.
- `key`, `login`, `additionalFields` are ciphertext. `additionalFields` decrypts to a JSON object of name to value. Names `key`, `login`, `url` are reserved (case-insensitive).
- When the owner's suite is revoked the same endpoints return a row with `blocked: true`, `blockedReason`, and no `key`, `login` or `additionalFields`. Clients handle both shapes.
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
- Argon2id (64 MiB, 3 iterations, parallelism 1, 32-byte output, 16-byte salt) is used only for password-protected sends and link shares. The web app uses `argon2-browser` (WASM).

## Ephemeral send payload

```
POST /api/v1/sends
{ "encryptedPayload": "<base64>", "payloadType": "text" | "credential",
  "maxViews": 1..cap, "hasPassword": false, "wrappedKey": null, "argon2idSalt": null }
```

- The payload is AES-256-GCM encrypted client-side under a random content key.
- Without a password the content key travels in the URL fragment of the share link and never reaches the server.
- With a password, `wrappedKey` is the content key wrapped under an Argon2id-derived key and `argon2idSalt` is its salt.
- Optional expiry is `ttlSeconds` (0 or absent means none, cap 2592000 = 30 days), stored as `expiresAt`.
- `maxViews` cap is 100 (server constant, not exposed by any endpoint). Unlimited views are refused. Five failed password attempts burn the send.
- Row JSON: `id, token, payloadType, hasPassword, maxViews, viewCount, remainingViews, expiresAt, createdAt`.
- Content blob layout is base64 of `[12-byte IV][ciphertext || tag]`; `wrappedKey` uses the same layout with the raw content key as plaintext; `argon2idSalt` is base64 of 16 random bytes.
- Recipient link: `<origin>/index.php/apps/keepiq/public/send/<token>#k=<base64url raw key, unpadded>` when no password; without the fragment when password-protected.
- The web app's "credential" payload type is free text, not structured JSON. `GET /api/v1/sends` lists the caller's sends; `DELETE` revokes.
- The recipient link points at the Keepiq web app's public page. The extension does not render it.

## Server-side settings that affect the client

- Admin `default_session_timeout` is one of `session`, `10min`, `30min` and is only a suggested default. The lock is client-enforced; the server has no session to kill.
- Admin `offline_cache_enabled` gates the manifest endpoint with a 403. Per-user `offline_cache_optin` exists too. When the manifest is unavailable the client falls back to paginated listing.
- Admin `min_password_length` and `min_password_score` apply to master password changes, which the extension does not perform.
