## Context

Keepiq's ephemeral send API is the server side of a Bitwarden-Send-like feature (ADR-003 "Ephemeral send payload"). The web app already implements the client side; the extension must produce byte-compatible ciphertext so the web app's public recipient page can decrypt it. Facts verified in the Keepiq app source:

- Create payload fields: `encryptedPayload`, `payloadType`, `maxViews`, `ttlSeconds`, `hasPassword`, `wrappedKey`, `argon2idSalt` (`lib/Service/EphemeralSendService.php` `create()` from about `:95`). Response is 201 with the row (`lib/Controller/EphemeralSendController.php:85`).
- `hasPassword` true requires both `wrappedKey` and `argon2idSalt`; false forbids both, so they are left out (`EphemeralSendService.php:207-225`).
- Expiry parameter is `ttlSeconds` in seconds, 0 or absent means no expiry, cap `TTL_CAP_SECONDS = 2592000` (30 days) (`EphemeralSendService.php:59`, `:182-193`).
- `maxViews` defaults to 1, cap `MAX_VIEWS_CAP = 100`, unlimited refused (`EphemeralSendService.php:52`, `:161-170`). The cap is not exposed by any endpoint, so the extension mirrors the constant.
- `payloadType` is `text` or `credential` (`EphemeralSendService.php:142-149`).
- Row shape: `id`, `token`, `payloadType`, `hasPassword`, `maxViews`, `viewCount`, `remainingViews`, `expiresAt`, `createdAt` (`lib/Db/EphemeralSend.php:199-211`). Token is 64 hex characters (`EphemeralSendService.php:73`, `:120`).
- Expired sends stay in `GET /api/v1/sends` until the hourly purge (`EphemeralSendPurgeJob.php:51`), so rows need an Expired state.
- A password send burns after 5 failed password attempts (`EphemeralSendService.php:66`, `:345-370`).
- `DELETE` answers 404 with a JSON body for an unknown or foreign id (`EphemeralSendController.php:135-139`).
- Client crypto (`src/send/sendCrypto.js`): fresh AES-256-GCM key (`:106-118`), blob is padded base64 of `[12-byte IV][ciphertext || tag]` (`:72-82`), base64url without padding (`:51-53`). The wrap is in `src/store/modules/ephemeralSend.js:64-69`: `wrappedKey` is the same blob layout over the raw 32-byte content key under the Argon2id key, `argon2idSalt` is base64 of 16 random bytes. Argon2id parameters 64 MiB, 3 iterations, parallelism 1, 32-byte output, password as UTF-8 (`src/crypto/argon2.js:19-34`, `:93-101`), which ADR-003 also records.
- Share link: `origin + generateUrl('/apps/keepiq/public') + '/send/' + token`, plus `#k=<base64url(raw key)>` when there is no password (`ephemeralSend.js:83-87`, `sendCrypto.js:131-134`). The recipient page is `EphemeralSendAccess` (`src/manifest.json:642-647`, `appinfo/routes.php:222-240`).
- The recipient page shows the decrypted string in one `<pre>` with one Copy button and ignores `payloadType` (`src/views/EphemeralSendAccess.vue:68-76`, `:172-173`). The web app's New send dialog has one textarea for both types (`src/modals/NewSendDialog.vue:24-35`).
- Vectors: `../tests/vectors/crypto/send.json` (`withoutPassword`, `withPassword` with a non-ASCII password, `argon2idKnownAnswer`), written by `tests/vectors/generate-crypto-vectors.mjs` from the web app's own modules.

## Goals / Non-Goals

**Goals:**
- Send tab with list, create, copy link, remove, and Send from item, following Bitwarden's Send tab layout.
- Ciphertext, wrapped key and link format identical to the web app so the recipient page just works.
- No send secret ever touches extension storage.

**Non-Goals:**
- Rendering or decrypting a received send (the web app's public page does that).
- File sends, editing a send, removing a password, disabling a send (no API).
- Syncing sends into the vault cache.
- A structured credential body with per-field copy on the recipient page. That is a web app change first, then a gated switch of `serializeCredential`.

## Decisions

- **Argon2id via `hash-wasm` 4.12.0, not `argon2-browser`.** The WASM is inlined as base64 with TypeScript types, so no `.wasm` asset or loader shim is needed. Output is standard Argon2id, which `send.json` checks. `argon2-browser` is unmaintained since 2022.
- **Argon2id and AES-GCM run in the background.** The popup posts plaintext and the send password; the background generates the content key, encrypts, derives, posts, and returns the row plus link. Only the background holds the API client and app password.
- **`wasm-unsafe-eval` declared once.** The `manifest` callback in `wxt.config.ts` sets `content_security_policy: { extension_pages: "script-src 'self' 'wasm-unsafe-eval'; object-src 'self';" }`; WXT writes the MV2 string for Firefox. Dev builds already get the keyword from WXT and production builds don't, so the check is on both production manifests.
- **Argon2 availability is probed.** `isArgon2Available()` compiles the 8-byte empty WASM module once in the background, because `typeof WebAssembly` is defined even when the CSP or a browser setting blocks compiling. hash-wasm compiles its WASM on the first `argon2id()` call; the background is one bundle, so there is no lazy `import()`.
- **Requests go through `PopupRequest` and `run`.** `send.*` are `PopupRequest` arms with `accountId`, dispatched in `src/background/requests.ts` like the generator's. Handlers in `src/send/handlers.ts` use `run(..., 'reply', accountId)` from `src/vault/write.ts`, which gives `locked`, `account_changed`, `recordOutcome` (so `offline` is kept current) and `writeError` mapping. Replies are `WriteResult<T>`; the only new code is `argon2_unavailable`. The background does not refuse on the offline flag: `offline` is the outcome of a request that found no server.
- **Re-check after Argon2id.** `createSendFor` reads `sessionEpoch(accountId)` before its first await and, after the wrap, refuses with `locked` when the epoch moved or the key is gone and with `account_changed` when another account is active, so a lock during the second-long derivation sends nothing.
- **An Argon2id failure is `argon2_unavailable`.** A derivation that fails after the probe passed, as when 64 MiB can't be allocated, is a `WriteFailure`, so it skips `recordOutcome` and doesn't touch the offline flag.
- **The background checks the input.** It refuses with `invalid` a blank body, a credential body without a password, max views outside 1 to 100 and `ttlSeconds` outside 3600 to 2592000, so a send without a time limit can't be made from the extension.
- **Server errors become codes.** A 400 maps to `invalid` and a 404 on delete is success; the server's English message is not shown, because the background sends codes, never text (ARCHITECTURE § Translations).
- **Send state lives in `SendProvider` inside `Shell`.** `hooks/useSends.tsx` holds the list, `passwordProtection` and the links; `Shell` renders the provider, is keyed per account, unmounts on lock and outlives tab switches, so a link is dropped on lock and account switch but survives leaving the Send tab. `Shell` owns `sendPage` (list, new, created) in its title, Back and page logic, as it does for the generator history. Background memory was ruled out: the MV3 worker stops about 30 s after the popup closes, and `storage.session` would put a `#k=` link on the same footing as plaintext.
- **Links without a password exist only in that state.** The create reply carries the link once; the provider keeps it in a `Map<sendId, link>`.
- **Leaving is blocked while a create runs.** `NewSend` reports busy to `Shell`, which disables Back, Cancel, Pop out and the account switcher. A reply that arrives after the form is gone opens no result screen; the link then exists only in the provider's `Map`.
- **Password-send links are rebuilt from `token`.** They hold no secret, so `send.list` returns `link` for password rows and Copy link always works for them, as in Bitwarden.
- **Components never touch crypto or the API.** Nothing under `entrypoints/popup/` imports `src/send/crypto.ts`, `argon2.ts`, `api.ts`, `link.ts`, `handlers.ts`, `src/crypto/` or `src/api/` (ADR-004). The popup imports the types from `src/messages.ts` and the pure helpers in `src/send/payload.ts` and `src/send/expiry.ts`.
- **Credential body is two plain lines with English labels.** `Username: <u>` newline `Password: <p>`, untranslated, because the recipient page shows plain text and can't translate it.
- **Expiry presets are Bitwarden's, mapped to `ttlSeconds`.** 1 h, 1 d, 2 d, 3 d, 7 d (default), 30 d, Custom in hours (1 to 720). All are within the server cap, which sets no list of values. "No time limit" (0) is not offered (ADR-001); rows with `expiresAt: null` show "Never expires".
- **Max views cap is a mirrored constant.** `MAX_VIEWS_CAP = 100` in `src/send/expiry.ts`, named after the server constant; a server 400 shows the localized `invalid` text.
- **No content cap.** The server sets none; a refused body is an `invalid` or `server_error` reply.
- **List is fetched per popup session, not synced.** `GET /api/v1/sends` on the first Send tab visit, re-fetched after create and remove.
- **Copy uses `useClipboard`.** The background clears the clipboard after 30 s, the fixed default until ext-settings makes it a setting (ADR-002).
- **Send from item reuses `item.decrypt`.** `Shell`'s `items.send(item)` asks for `fields: ['login','key']`, which already refuses blocked and use-only copies and answers `locked`, then opens the Send tab on `NewSend` with the values as its initial state; a login without a stored password opens with it empty. They are dropped when `NewSend` unmounts (ADR-004). A form that differs from its empty or prefilled start uses `useUnsavedChangesGuard`, as `ItemForm` does.
- **Send in the More menu follows the other actions.** Hidden for non-login types; disabled with a `disabledReason` for blocked, use-only, an expiring share (a send would outlive the access, as Clone's `expiringNoClone`) and offline. Read-only shares may send, since they can copy the password anyway.
- **Focus follows the page.** New send focuses its first field, the result screen its heading, Done the new send's row, and a removal the next row or New send.
- **Dutch says "verzending" and "Verzenden"**, as the web app's catalog does.

## Module layout

Added:
- `src/send/argon2.ts`: `deriveSendKey(password, salt)` over `hash-wasm` `argon2id` with the fixed parameters, returning a non-extractable AES-GCM `CryptoKey`, and `isArgon2Available()`.
- `src/send/crypto.ts`: content key generation, IV-prefixed AES-GCM blob, `wrapContentKey`, base64url on top of `src/crypto/base64.ts`.
- `src/send/link.ts`: `buildShareLink(serverUrl, token, rawKey | null)`.
- `src/send/api.ts`: `listSends`, `createSend`, `deleteSend` over the API client, with the row shape checked.
- `src/send/handlers.ts`: the `send.*` handlers.
- `src/send/payload.ts`: `serializeCredential(username, password)`.
- `src/send/expiry.ts`: presets to `ttlSeconds`, custom-hours check, `MAX_VIEWS_CAP`, `TTL_CAP_SECONDS`.
- `entrypoints/popup/hooks/useSends.tsx`: `SendProvider` and `useSends()`, with the list, load status, links and actions, each a `request()` with `useShell().accountId`.
- `entrypoints/popup/views/send/SendList.tsx`, `NewSend.tsx`, `SendCreated.tsx`.
- `entrypoints/popup/components/SendRow.tsx`: type icon, label, views counter, expiry (or Never expires, or Expired) on its own line, Password badge, Copy link or "Link no longer available", Remove.
- `scripts/manifest-csp.test.ts`: the manifest callback emits `'wasm-unsafe-eval'` for both browsers.
- Reused: `TextField` (`type="password"` for the access password and the credential password, `type="number"` for max views and custom hours, since it can show "Between 1 and 100"), `TextArea`, `Select` (expiry), `SubTabs` (type), `TextField` read-only (the link on the result screen), `ConfirmDialog`, `EmptyState`, `Banner` with a Retry action, `PageFooter`, `Button`.

Edited:
- `src/messages.ts`: send types, the three arms in `PopupRequest`, `PopupReplies` and `POPUP_REQUEST_KINDS`, and `argon2_unavailable`.
- `src/background/requests.ts`: dispatch `send.*`; `src/vault/write.ts`: export `run`; `src/crypto/base64.ts`: `toBase64Url`, `fromBase64Url`.
- `src/testing/vectors.ts`: the `send` vector name.
- `entrypoints/popup/views/Shell.tsx`: `SendProvider`, `sendPage`, the busy lock, the Send tab and the Header New button on it; `components/Header.tsx`: `busy`; `shell-context.ts`: `ItemActions.send`; `components/ItemCard.tsx` and `views/ItemDetail.tsx`: the Send action.
- `entrypoints/popup/errors.ts`: wording for `argon2_unavailable`; `locales/en.yml`, `locales/nl.yml`; `entrypoints/popup/popup.css`.
- `wxt.config.ts`: the CSP; `package.json`: `hash-wasm`.

Removed: `entrypoints/popup/views/Placeholder.tsx` and the `common.laterUpdate` key.

## Message contract

```ts
export type SendPayloadType = 'text' | 'credential'

export interface SendRow {
	id: string
	token: string
	payloadType: SendPayloadType
	hasPassword: boolean
	maxViews: number
	viewCount: number
	remainingViews: number
	/** `null` never expires. */
	expiresAt: string | null
	createdAt: string
	/** Password sends only: their link holds no secret. */
	link?: string
}

export interface SendCreateInput {
	payloadType: SendPayloadType
	/** Text body, or the two-line credential body from src/send/payload.ts. */
	content: string
	maxViews: number
	ttlSeconds: number
	/** Absent or empty means no password. */
	password?: string
}

export type SendRequest = { accountId: string } & (
	| { kind: 'send.list' }
	| { kind: 'send.create'; input: SendCreateInput }
	| { kind: 'send.delete'; id: string }
)

// PopupReplies
'send.list': WriteResult<{ sends: SendRow[]; passwordProtection: boolean }>
'send.create': WriteResult<{ row: SendRow; link: string }>
'send.delete': WriteResult<null>
```

`passwordProtection` is `isArgon2Available()`. Every `send.*` is an interaction. The background keeps no `input`, content key or link after replying.

## Risks / Trade-offs

- [Argon2id at 64 MiB in an MV3 service worker takes about one second and a large allocation] → Show the "Protecting with password…" state; the pending message keeps the worker alive, far under Chrome's 5-minute event limit.
- [`wasm-unsafe-eval` widens the extension CSP] → It only permits WebAssembly compilation, not `eval`; no other source is added and the WASM is a pinned dependency.
- [A link with a `#k=` fragment is the only copy of the key] → The result screen says so, Copy is one click, and the row keeps Copy link until the popup closes.
- [Server cap constants drift from the mirrored ones] → The server refuses with 400, shown as `invalid`, and ADR-003 is the place to update the values.
- [The recipient sees a credential as two text lines, not fields] → Matches what the web app produces today.
