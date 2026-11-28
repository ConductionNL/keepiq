---
kind: code
depends_on: [ext-vault-edit, ext-i18n]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-i18n
  - ext-generator
  - ext-send
  - ext-theme
  - ext-settings
  - ext-autofill
---

## Why

Bitwarden's Send tab lets a user hand someone a secret through a self-destructing link without creating a vault item. Keepiq already has the matching server feature, the ephemeral send API (ADR-003), but the extension cannot reach it, so a user has to open the web app for the one action that is most often done from a browser tab.

## What Changes

- The popup's Send tab, a placeholder today, gets a "My sends" list fetched from the server, with per-row Copy link and Remove, and an empty state.
- A "New send" form: type (Text or Credential), content, max views, expiry, optional password, then Create. The result screen shows the link with a Copy button.
- "Send" in an item's More menu, in the list row and in the detail, opens the form prefilled with the item's decrypted username and password as a credential send. It shows for login items only and is disabled with the reason for blocked, use-only, expiring shares and offline.
- Client-side send crypto in the background: AES-256-GCM under a fresh content key, key in the URL fragment or wrapped under an Argon2id-derived key when a password is set. This adds the `hash-wasm` dependency and a `wasm-unsafe-eval` CSP entry to the manifest.
- Creation works only while unlocked and online. Nothing about a send (plaintext, content key, link fragment) is written to any extension storage.

## Capabilities

### New Capabilities
- `send`: creating, listing and revoking Keepiq ephemeral sends from the popup, and prefilling a send from a vault item.

### Modified Capabilities

None.

## Deviations from Bitwarden

Bitwarden feature mirrored: the Send tab (list, new send, copy link, delete). Deviations, each forced by the Keepiq API (ADR-003, `EphemeralSendService`) unless marked otherwise:

- No File send type. Keepiq accepts `payloadType` `text` or `credential` only. Credential is offered as a second type instead.
- No send name. Keepiq sends have no name field, so each row shows a label derived from type and creation time.
- Max access count is required, defaults to 1 and is capped at 100. Bitwarden defaults to unlimited; Keepiq refuses unlimited views.
- One expiry instead of Bitwarden's separate Deletion date and Expiration date. Keepiq has a single TTL, and an expired send is deleted by the hourly purge. Bitwarden's presets (1 hour, 1 day, 2, 3, 7, 30 days, custom) are kept with 7 days as default; all fit Keepiq's 30-day cap. Keepiq's "no time limit" is not offered (not forced: Bitwarden has no such option), but sends made that way in the web app are listed as "Never expires".
- A send without a password has its link only for the popup session it was created in. Keepiq never stores the content key, so a closed popup cannot rebuild the `#k=` fragment; such rows say "Link no longer available". Password sends carry no secret in the link, so their Copy link always works, as in Bitwarden.
- No Remove password, Disable send, Notes, Hide email or Hide text by default. Keepiq has no such fields and no update endpoint.
- The send password is hashed with Argon2id, not PBKDF2, because the Keepiq recipient page derives the key that way.
- The sends list is not part of the vault sync and is not cached on disk. It is fetched from the server per popup session, because Keepiq's offline manifest does not carry sends and a stale list would misreport remaining views.
- No length limit on the content (not forced). Keepiq sets none, and a body the server refuses comes back as an error.

## Keepiq API used

- `GET /api/v1/sends`
- `POST /api/v1/sends`
- `DELETE /api/v1/sends/{id}`

The routes and every field used exist since Keepiq 0.1.4 and the path-form recipient link since 0.3.0, both older than `MIN_KEEPIQ_VERSION` 0.3.12, so nothing is gated (ADR-003). The share link points at the web app's public page `<base>/index.php/apps/keepiq/public/send/<token>`; the extension only builds that URL and never calls the public routes.

## Impact

- New module `src/send/` (crypto, Argon2id wrapper, payload serialisation, expiry presets, API calls, link builder, request handlers).
- `src/messages.ts` gains `send.list`, `send.create` and `send.delete` on `PopupRequest`, each naming its account, and the write error code `argon2_unavailable`. They are dispatched in `src/background/requests.ts`; `entrypoints/background.ts` is unchanged. The prefill reuses `item.decrypt`.
- The popup gains views `SendList`, `NewSend` and `SendCreated` under `entrypoints/popup/views/send/`, the component `SendRow` and the hook `useSends` with its `SendProvider`. The provider is mounted inside `Shell`, so send state is dropped on lock and on an account switch, and `views/Placeholder.tsx` goes. The item actions in `shell-context.ts`, `ItemCard.tsx` and `ItemDetail.tsx` gain Send. Every string is in `locales/en.yml` and `locales/nl.yml`.
- `wxt.config.ts` declares `content_security_policy` with `'wasm-unsafe-eval'` once; WXT writes the MV2 string form for Firefox.
- New dependency `hash-wasm` 4.12.0 (Argon2id with the WASM inlined, about 11 kB gzipped). No new permissions.
