---
kind: code
depends_on: [ext-vault-browse]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-generator
  - ext-send
  - ext-settings
  - ext-autofill
---

## Why

Bitwarden's Send tab lets a user hand someone a secret through a self-destructing link without creating a vault item. Keepiq already has the matching server feature, the ephemeral send API (ADR-003), but the extension cannot reach it, so a user has to open the web app for the one action that is most often done from a browser tab.

## What Changes

- The popup's Send tab gets a "My sends" list fetched from the server, with per-row Copy link and Remove, and an empty state.
- A "New send" form: type (Text or Credential), content, max views, expiry, optional password, then Create. The result screen shows the link once with a Copy button.
- "Send from item" in an item's More menu (ext-vault-browse) opens the form prefilled with the item's decrypted username and password as a credential send.
- Client-side send crypto in the background: AES-256-GCM under a fresh content key, key in the URL fragment or wrapped under an Argon2id-derived key when a password is set. This adds a WASM Argon2id dependency and a `wasm-unsafe-eval` CSP entry to the manifest.
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
- One expiry instead of Bitwarden's separate Deletion date and Expiration date. Keepiq has a single TTL, and an expired send is deleted. Bitwarden's presets (1 hour, 1 day, 2, 3, 7, 30 days, custom) are kept; the default is 7 days as in Bitwarden. Keepiq's "no time limit" option is not offered, because Bitwarden has no such option (not forced, see design Open Questions).
- Copy link works only for sends created in the current popup session. Bitwarden can rebuild a link at any time because it stores the send key in the vault; Keepiq never stores the content key anywhere, so a link that left the popup cannot be recovered. Older rows say "Link no longer available".
- No Remove password, Disable send, Notes, Hide email or Hide text by default. Keepiq has no such fields and no update endpoint.
- The send password is hashed with Argon2id, not PBKDF2, because the Keepiq recipient page derives the key that way.
- The sends list is not part of the vault sync and is not cached on disk. It is fetched from the server per popup session, because Keepiq's offline manifest does not carry sends and a stale list would misreport remaining views.

## Keepiq API used

- `GET /api/v1/sends`
- `POST /api/v1/sends`
- `DELETE /api/v1/sends/{id}`

The share link points at the web app's public page `<base>/public/send/<token>`; the extension only builds that URL and never calls the public routes.

## Impact

- New module `src/send/` (crypto, Argon2id wrapper, payload serialisation, expiry presets, API calls, link builder).
- `src/messages.ts` gains `send.list`, `send.create`, `send.delete` and `send.prefillFromItem`.
- `entrypoints/background.ts` registers the send handlers. The popup gains React views `SendList`, `NewSend` and `SendCreated` under `entrypoints/popup/views/send/`, components `SendRow`, `ExpirySelect` and `MaxViewsInput`, and a `SendProvider` context with a `useSends` hook (ADR-004). Components talk to the background only through the `send.*` messages.
- `wxt.config.ts` gains a `content_security_policy` with `'wasm-unsafe-eval'` for Chrome MV3 and the string form for Firefox MV2.
- New dependency `hash-wasm` (Argon2id entry point about 30 KB minified with the WASM inlined). No new permissions.
- Depends on ext-vault-browse for the popup shell, the item More menu hook and the API client, crypto helpers and clipboard helper from earlier changes in the chain.
