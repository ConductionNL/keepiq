# ADR-002: Key lifetime and vault caching

**Status**: accepted

**Date**: 2026-09-22

This decision was first worked out from first principles, asking how a password manager extension should balance usability and security before looking at any Keepiq constraint. This file is that reasoning reconciled with Keepiq's actual crypto and API (ADR-003). Where Keepiq forces a compromise, it is named.

## Context

Keepiq has no vault key. The only secret-bearing key is the user's RSA-4096 private key, unwrapped from an AES envelope with a key derived from the master password at 600 000 PBKDF2 iterations (ADR-003). Every ciphertext field is RSA-OAEP to that key. The server holds no client session, no revision counter and no revocation stamp. The web app already ships an encrypted offline snapshot and treats the lock as a purely client-side state.

"Cache or re-fetch" is two questions. Where the ciphertext lives barely matters, because it is useless without the private key. How long the private key lives is the whole decision. Re-fetching ciphertext while keeping the key in memory adds latency and a network dependency for no security gain.

## Threat model

Four adversaries against the data the extension holds. "Exposed" means the adversary can read it in the given state; the storage table below is checked against this, not the other way round.

| Data | Remote server compromise | Local infostealer (file grab) | Walk-up physical access, unlocked machine | Hostile web page via content script |
| --- | --- | --- | --- | --- |
| Master password | Never (never sent) | Never stored; keylogger is out of scope for this design | Never stored | Never |
| RSA private key | Never | Not on disk (`storage.session` is memory-backed); "Never" timeout is the exception and is warned | Exposed while unlocked, for the timeout window | Never (`storage.session` is not exposed to content scripts) |
| Decrypted item fields | Never | Never on disk | Exposed for what is on screen while unlocked | One credential per fill, after explicit user choice |
| Ciphertext and plaintext metadata (names, URLs, folders) | Already held by the server | Ciphertext useless without the key; names and URLs leak which sites the user has accounts on | Names and URLs visible while unlocked | Never |
| App password | Already held by the server | Exposed; grants read and write of ciphertext via the API, not decryption | Exposed | Never |

Consequences drawn from the table:

- The infostealer row is why key material never touches `storage.local`: ciphertext plus app password on disk gives an attacker exactly what the server already has, no more.
- The app password is the weakest item. Nextcloud app passwords are long-lived and have no refresh flow, so the short-lived token the first-principles design asked for is not available. Mitigations: it is scoped to one Nextcloud user, revocable in one click in Nextcloud, and cannot decrypt anything or destroy the vault (destructive suite operations need a vault-key proof, ADR-003).
- The walk-up row is what the idle timeout bounds. The physical-access adversary is the one the timeout defends against; malware already running as the user can also keylog, so the timeout buys little there.
- The content script row is why URL matching and decryption happen in the background and the page receives one credential at a time.

## Decision

The extension caches ciphertext on disk and never persists the private key or anything that derives it.

| Data | Lives in | Cleared by |
| --- | --- | --- |
| Master password | Nowhere. Used to derive, then dropped. | n/a |
| PBKDF2-derived unlock key | Memory, for the duration of one unlock | End of unlock |
| RSA private key | `browser.storage.session` as PKCS#8 bytes, imported to a non-extractable `CryptoKey` per worker generation | Lock, timeout, browser restart, extension reload |
| Decrypted item fields | Memory, per popup render or per fill | Popup close or fill completion |
| Suite row, secret rows (ciphertext plus plaintext metadata), folders, types | `browser.storage.local` | Logout, account removal, suite change (`encryptionSuiteId` or `unlockKeyEpoch` differs on sync) |
| App password | `browser.storage.local` | Logout, account removal, server returns 401 |
| Account list, active account id, settings | `browser.storage.local` | Account removal |

`storage.session` is memory-backed, holds up to 10 MB, is cleared when the extension is disabled, reloaded, updated or the browser restarts, and is not exposed to content scripts unless `setAccessLevel` changes that. Do not change that default.

The private key is stored as bytes, not as a `CryptoKey`, because `storage.session` values must be serializable. A non-extractable `CryptoKey` cannot survive an MV3 service worker restart, which happens after about 30 seconds idle. The worker re-imports the bytes into a non-extractable key on wake. This trades a small window of raw key bytes in `storage.session` for a lock that survives worker restarts. Firefox below 115 has no `storage.session`; there the key lives only in the background page's memory (MV2 pages are persistent), and the same lock rules apply.

## Lock and logout

Lock and logout are different states and are labelled differently everywhere in the UI.

- **Lock** purges the private key. Ciphertext, metadata, app password and account list stay. Unlock needs the master password, or a PIN when the user enabled it, with no server round trip.
- **Logout** purges everything belonging to the account, including the app password. Returning needs the app password again.

Defaults: vault timeout 15 minutes idle, timeout action "Lock". A browser restart always locks regardless of the setting, because `storage.session` does not survive it. Options: Immediately, 1 minute, 5 minutes, 15 minutes, 30 minutes, 1 hour, 4 hours, On system lock, On browser restart, Never, Custom. Idle means time since the user last interacted with the extension, not system sleep. "Never" carries an explicit warning because it writes key material to `storage.local`; Bitwarden shipped it as the default for years and regretted it. Whether Custom should be capped (for example at 24 hours) is open. The maximum timeout and the forced action are read from one config object so an admin policy can later clamp them.

The extension's lock is independent of the web app's. Keepiq's web app timeout preference (`session_timeout`) is shown in settings as the server default but does not drive the extension. Logging out of the Nextcloud web UI does not lock the extension.

## Revocation

A cached vault on a machine the user no longer controls cannot be remotely wiped. Keepiq has no security stamp, so the compensating mechanisms are:

- The app password. Revoking it in Nextcloud makes every request return 401. On 401 the extension purges the account's ciphertext, key and app password before doing anything else and returns the account to the logged-out state.
- The suite. If a sync returns an active suite whose `id` or `unlockKeyEpoch` differs from the cached one, the cached ciphertext is undecryptable. The extension purges the cache and the key and forces a fresh unlock and full sync.
- `blocked: true` rows. A revoked suite yields metadata-only rows. The extension shows them as blocked and never fills from them.

The window between revocation and next contact is bounded by the sync interval. Sync runs on unlock, on popup open when the last sync is older than the interval, on a fixed interval while unlocked, and after every local write. The interval is a named constant, not an emergent property.

## Sync

There is no cursor. An unchanged vault must still cost one cheap call: sync first asks `GET /api/v1/secrets?sort=updated_at&direction=desc&limit=1` and compares the newest `updatedAt` and `total` with the cached snapshot; only when either differs, or the folder and type lists are stale, does it fetch `GET /api/v1/offline/manifest` and replace the local snapshot atomically. Deletions change `total`, edits and creates change `updatedAt`. When the manifest returns 403 (admin disabled) or 404, sync falls back to paginated `GET /api/v1/secrets` plus folders and types. A full fetch is acceptable because rows are small and vaults are hundreds of items, not hundreds of thousands.

## Decryption is lazy

Only `name`, `url`, `typeId` and `folderId` are needed for list, search and URL matching, and they are plaintext. The extension decrypts `login` for the list row's subtitle on render and `key` only on copy, fill or detail view. Nothing decrypted is written to any storage. This keeps a memory dump of the popup or worker small and matches Keepiq's own web app.

## Content scripts

Content scripts run in hostile pages. They never hold vault state, never receive the private key, and receive exactly one credential for one fill over a runtime message after the user explicitly picked it. URL matching happens in the background.

## Website icons

The extension never fetches favicons from the sites in the vault or from an icon service. Both tell someone outside the user's Keepiq server which domains the user has accounts on, and when the vault was opened. Items show a type icon instead.

The intended future path is a favicon stored on the secret itself in Keepiq, as base64 image data set by the web app when a secret is created or its URL changes, and served with the row like any other plaintext metadata. The extension would then render icons straight from the cached snapshot with no request at all. That is a Keepiq change, not an extension one; until it lands, no icon fetching is added under any setting.

## Clipboard

Fill is preferred over copy. Copy auto-clears the clipboard after a configurable delay, default off in Bitwarden but offered with the same options (10 seconds to 5 minutes, or never).

## Consequences

- Unlock costs one PBKDF2 derivation at 600 000 iterations, roughly half a second on a laptop. Short timeouts multiply that cost and create pressure to weaken the KDF; 15 minutes is the compromise, and the option list goes no lower than 1 minute for a reason.
- Rotating the master password or the suite in the web app invalidates the extension's cache on next sync. This is expected and cheap.
- Because `PUT /api/v1/secrets/{id}` is a whole-blob, last-write-wins patch for `additionalFields`, the extension re-fetches the item before editing and writes only the fields the user changed.
