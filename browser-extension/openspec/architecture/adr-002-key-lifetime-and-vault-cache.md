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
| Decrypted item fields | Never, except the whole value of an item whose `key`, `login` or `additionalFields` the user edits: any such item for the owner's server, shared or not, and one shared with its own users for a partner's server. When the item has a field the form does not show, the owner's server gets only the changed fields, and a partner share stops the save. A swap in a sync before the edit form opens makes that value any item's, as the form shows it (see Sharing) | Never on disk | Exposed for what is on screen while unlocked | One credential per fill, after explicit user choice |
| Ciphertext and plaintext metadata (names, URLs, folders) | Already held by the server | Ciphertext useless without the key; names and URLs leak which sites the user has accounts on | Names and URLs visible while unlocked | Never |
| App password | Already held by the server | Exposed; grants read and write of ciphertext via the API, not decryption | Exposed | Never |

Consequences drawn from the table:

- The infostealer row is why key material never touches `storage.local`: ciphertext plus app password on disk gives an attacker exactly what the server already has, no more. The rule: `storage.local` may hold ciphertext, the app password, and the PIN sealed under a key derived from the private key; nothing on disk may open the private key for less than the master password, except under the warned "Never" timeout.
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
| Decrypted item fields | Memory, per popup render, per inline list render (usernames only) or per fill | Popup close, inline list close or fill completion |
| A login's verification code, never its `totp` seed outside the background and the popup | The inline list page's memory while shown; background memory per fill, list render or menu click; the popup while the detail view shows it | Inline list close, the end of that handler, or the view closing |
| A new authenticator key, typed or read from a QR code; a saved one never goes back to the popup | Popup memory in the edit form; background memory while it is saved | Save, the form or popup closing; the save's end |
| The picture of the tab that Scan QR code on page reads | Popup memory | The end of the read |
| Ciphertext an edit form opened, with the row's `updatedAt` and the source's `sourceUpdatedAt`, the 10 newest forms | `browser.storage.session` | That form's accepted save, or 10 newer entries; lock, timeout, logout, account removal, browser restart, extension reload. Closing the form or popup leaves it. |
| Suite row, secret rows (ciphertext plus plaintext metadata), folders, types | `browser.storage.local` | Logout, account removal, suite change (`encryptionSuiteId` or `unlockKeyEpoch` differs on sync) |
| App password | `browser.storage.local` | Logout, account removal, server returns 401 |
| Inline list session: a random key, the tab, frame and frame URL, its mode and the offered item ids; no plaintext | `browser.storage.session`; background-page memory on Firefox below 115 | The list closing, the next list in the tab, tab close or navigation, lock, logout, account switch, browser restart, extension reload |
| `clipboardCode`: the account whose verification code the background copied, never the code | `browser.storage.session`; background-page memory on Firefox below 115 | The clipboard clear, any other copy, that account's lock or logout (which clears the clipboard at once), browser restart, extension reload |
| Generated values, the 50 newest per account, in plaintext | `browser.storage.session` | Clear in the History view; lock, timeout, logout, account removal, browser restart, extension reload |
| Values generated while locked, sealed to the certificate proven at the last unlock | `browser.storage.session` | The first history request after the unlock, which merges them into the history; Clear in the History view; a lock after that unlock, other than for a suite change; logout, account removal, the two-factor block, browser restart, extension reload |
| Generator options | `browser.storage.local` | Account removal |
| PIN session copy: the private key wrapped under the PIN, and its failed-attempt count | `browser.storage.session`; background-page memory on Firefox below 115. Never `storage.local`. | Fifth wrong PIN, removing the PIN, logout, account removal, server returns 401, suite change, the two-factor policy withholding the key, browser restart, extension reload. Not a lock, by hand or by the timeout. |
| PIN seal: the PIN under a key derived from the private key | `browser.storage.local` | The same as the session copy, except browser restart and extension reload. |
| Organisation policy, as the generator reads it | `browser.storage.local` | Logout, account removal |
| Organisation idle lock maximum and export switch | `browser.storage.local` | Account removal. Kept through a logout, so a new login is never unclamped. |
| The certificate the last unlock proved, public | `browser.storage.local` | A failed proof, logout, account removal, the two-factor block |
| Account list, active account id, settings | `browser.storage.local` | Account removal |

`storage.session` is memory-backed, holds up to 10 MB, is cleared when the extension is disabled, reloaded, updated or the browser restarts, and is not exposed to content scripts unless `setAccessLevel` changes that. Do not change that default.

Generated values are plaintext secrets that may not be saved anywhere yet, so they get the private key's storage and its clearing, never `storage.local`. The generator works while locked, but the background returns the history only for an unlocked account, and a value generated while locked is stored only sealed to the certificate proven at the last unlock, so no stored generated value is readable without unlocking.

The private key is stored as bytes, not as a `CryptoKey`, because `storage.session` values must be serializable. A non-extractable `CryptoKey` cannot survive an MV3 service worker restart, which happens after about 30 seconds idle. The worker re-imports the bytes into a non-extractable key on wake. This trades a small window of raw key bytes in `storage.session` for a lock that survives worker restarts. Firefox below 115 has no `storage.session`; there the key lives only in the background page's memory (MV2 pages are persistent), and the same lock rules apply.

## Lock and logout

Lock and logout are different states and are labelled differently everywhere in the UI.

- **Lock** purges the private key. Ciphertext, metadata, app password, account list and both PIN copies stay. Unlock needs the master password, or a PIN when the user enabled it, with no server round trip.
- **Logout** purges everything belonging to the account, including the app password. Returning needs the app password again.

Defaults: vault timeout 15 minutes idle, timeout action "Lock". A browser restart always locks regardless of the setting, because `storage.session` does not survive it. Options: Immediately, 1 minute, 5 minutes, 15 minutes, 30 minutes, 1 hour, 4 hours, On system lock, On browser restart, Never, Custom. Idle means time since the user last interacted with the extension, not system sleep. "Never" carries an explicit warning because it writes key material to `storage.local`; Bitwarden shipped it as the default for years and regretted it.

The organisation's idle lock maximum caps every option. Keepiq's admin sets it as `maxIdleMinutes` (ADR-003), one of 1, 5, 15, 30, 60 or 240 minutes, 240 when the admin set none, and the server does not say which. The extension uses the lower of the user's choice and the maximum:

- Never, On browser restart and Custom values above the maximum are clamped to it, so with the default no option outlives 4 hours idle. This also caps Custom.
- On system lock locks on system lock or at the maximum, whichever comes first.
- Immediately locks when the popup closes or after the maximum idle time, whichever comes first.
- The maximum is read with the generator policy on every sync and login, and the last value seen applies offline and through a logout. Before the first read the user's choice applies unclamped.
- A clamp never rewrites the stored choice, so a raised maximum restores it. A stored maximum deletes the Never copy of the key from `storage.local` at once.

Keepiq has no forced timeout action, so the action is always the user's.

The extension's lock is independent of the web app's. Keepiq's web app timeout preference (`session_timeout`) does not drive the extension, and the extension does not show it. Logging out of the Nextcloud web UI does not lock the extension.

A PIN wraps the private key under a key derived from the PIN for the browser session (see the table). Setting one needs the master password again, so whoever finds the vault unlocked cannot set a PIN to outlast the timeout. A PIN has at least 6 characters.

- **Across a restart.** The PIN itself is sealed in `storage.local` with AES-256-GCM under a key derived (HKDF) from the private key, bound to the account, suite and epoch. After a restart the master password unlocks once, and the seal then rebuilds the session copy. A PIN alone never unlocks after a restart.
- **Why the seal is authenticated.** A seal to the public key could be written by anyone who can write storage, planting a PIN of their choosing for the next master-password unlock to adopt. Only a key derived from the private key can make a seal that opens.
- **Accepted: rollback.** Whoever can write `storage.local` can restore an old seal after five wrong PINs or after the PIN was turned off, and the next master-password unlock re-enables that PIN. That attacker could already set the timeout to Never, so the seal adds no power they lack.
- **Accepted: PIN reuse.** Whoever opens the private key can read the PIN, so a PIN reused elsewhere leaks with the vault.
- **The five-try limit binds the unlock screen only.** Anyone with the running browser can read the session copy, on Firefox through about:debugging, and try every 6-digit PIN offline within minutes on a GPU. A PIN is only as strong as its length and character set.

## Revocation

A cached vault on a machine the user no longer controls cannot be remotely wiped. Keepiq has no security stamp, so the compensating mechanisms are:

- The app password. Revoking it in Nextcloud makes every request return 401. On 401 the extension purges the account's ciphertext, key and app password before doing anything else and returns the account to the logged-out state.
- The suite. If a sync returns an active suite whose `id` or `unlockKeyEpoch` differs from the cached one, the cached ciphertext is undecryptable. The extension purges the cache and the key and forces a fresh unlock and full sync.
- `blocked: true` rows. A revoked suite yields metadata-only rows. The extension shows them as blocked and never fills from them.

A compromised server could also keep the suite id and epoch and swap only the certificate, so new secrets would be encrypted to its own key. The extension can catch this where the web app cannot, because its code does not come from the server: before it encrypts with a certificate, it proves the certificate matches the unlocked private key, which only the master password opens, and refuses every write otherwise. The lock screen generator seals only to a certificate the last unlock proved this way. Before each write it also asks for the active suite: the server stamps a write with that suite whatever key it was encrypted to, so a key changed on another device since the last sync locks instead of writing an item no one can open.

The window between revocation and next contact is bounded by the sync interval. Sync runs on unlock, on popup open when the last sync is older than the interval, on a fixed interval while unlocked, and after every local write. The interval is a named constant, not an emergent property.

## Sharing

Each recipient of a shared item holds a copy encrypted to their own key, so an edit is re-encrypted in the background for every copy the server lists, and saved with them in one share-save that writes everything or nothing (`ext-share-sync`).

- Local recipients' certificates are trusted as the server serves them, as in the web app and as Bitwarden trusts organisation membership. Only the user's own certificate can be proven against the unlocked key; a compromised server can therefore list a recipient the user never chose and receive the changed fields of any item the user edits, shared or not. Accepted, and the extension does not warn before the save.
- Partner-organisation certificates must chain through CA certificates to the partner root the owner's server pinned and name the share's recipient. Both servers are still trusted, since the pin comes in the owner server's reply and the partner server holds its own CA keys; the check catches a certificate for the wrong user, an expired one, or a chain from another partner. The server names the partner shares a save must carry and they get the whole value, so the owner's server alone can make up a share under its own pin and receive the `key`, `login` and `additionalFields`, and the `totp` and `passkeys` when it says the partner stores them, of any item whose value the user edits, shared or not, unless the item has a field the form does not show, when the save stops. Accepted, as in the web app.
- Every field is encrypted to the same key and nothing ties a ciphertext to its item, so a save sends what the user typed. The edit and clone fetches open a ciphertext only when it equals the vault cache's for that item, and a save gives partners untouched fields only as its own form's edit fetch recorded them, found by the random `recordId` the popup sends back, and only while the record's version is the save's `baseUpdatedAt`; a save naming none or an unknown one has no record. The fetch records a field only when it is empty or the form shows it in full (`shownInFull` in `src/vault/fields.ts`), so an unseen one, such as a passkey's credential or a card member the form has no field for, is never vouched for. The form shows every type's login except a passkey's, so an item the web app or the extension wrote has an unseen field only when it is a passkey. A login's `totp` and `passkeys` are recorded whenever the row holds them: the form by design shows only that they are saved, as the web app does, and the cache match is what vouches for them. The edit form, and a clone until another type is picked, renders under the type name the fetch vouched under, not the popup's snapshot, so a type renamed in between cannot hide a field. Without a record for every untouched field, a save the server requires partner shares for fails with `unverified` and writes nothing; local copies need only the changed fields. A server that puts another item's ciphertext into this item's row in a sync before the edit form opens is outside these checks. Accepted: the form then shows that value as this item's, masked where it is a password, and the save sends it on. That can be any item's value, not only the edited one's.
- The server orders concurrent saves: share-save refuses a stale `baseUpdatedAt`, and on the copy-editor path a stale `sourceBaseUpdatedAt`, the source version the edit form read or, without the form's record, the current one. The extension keeps no save state, queue or Retry.
- The decrypted value lives in background memory for the one request. Of item data, `storage.session` holds only what an edit form opened, as ciphertext, with the row and source versions.

## Sync

There is no cursor. An unchanged vault must still cost one cheap call: sync first asks `GET /api/v1/secrets?sort=updated_at&direction=desc&limit=1` and compares the newest `updatedAt` and `total` with the cached snapshot; only when either differs, or the folder and type lists are stale, does it fetch `GET /api/v1/offline/manifest` and replace the local snapshot atomically. Deletions change `total`, edits and creates change `updatedAt`. When the manifest is refused (admin disabled, ADR-003) or returns 404, sync falls back to paginated `GET /api/v1/secrets` plus folders and types. A full fetch is acceptable because rows are small and vaults are hundreds of items, not hundreds of thousands.

## Decryption is lazy

Only `name`, `url`, `typeId` and `folderId` are needed for list, search and URL matching, and they are plaintext. The extension decrypts `login` for the list row's subtitle on render and `key` only on copy, fill or detail view. Nothing decrypted is written to any storage. This keeps a memory dump of the popup or worker small and matches Keepiq's own web app.

## Content scripts

Content scripts run in hostile pages. They never hold vault state, never receive the private key, and receive exactly one credential for one fill over a runtime message after the user explicitly picked it. URL matching happens in the background.

UI shown inside a page (the save bar, the inline list) is an extension page in an iframe the content script hosts but cannot read. The inline list may show the names and decrypted usernames of the logins matching its frame, which live only in that page and in the background while it answers. It shows on every qualifying field, matches or not, so its presence does not tell the page whether a login matches; its height fits up to five rows, so the page can count the matches, which is accepted as useless to a site. A pick counts only while the list was visibly on top, so the page cannot click it on the user's behalf. The list shows only in the top frame and in frames whose ancestors are all same-origin, on both browsers, so the extension can read everything above it. On Chrome, IntersectionObserver v2 plus the same-origin ancestor checks; masks are covered by refusing cross-origin ancestors. On Firefox the extension samples visibility, which is best effort: an overlay that lets clicks through, or a style changed only between samples, goes unnoticed.

## Scanning a QR code

Scan QR code on page takes a picture of the tab under the toolbar popup with `tabs.captureVisibleTab`, under `activeTab`, only on the user's click, and reads it with jsQR in the popup; no content script takes part, so the page cannot hand the extension a key or see one. The picture holds whatever the tab shows and is dropped when the read ends. `<all_urls>` would also allow a picture without a click, so it is not requested; Firefox below 126 therefore cannot scan, and the user pastes the key.

## Website icons

The extension never fetches favicons from the sites in the vault or from an icon service. Both tell someone outside the user's Keepiq server which domains the user has accounts on, and when the vault was opened. Items show a type icon instead.

The intended future path is a favicon stored on the secret itself in Keepiq, as base64 image data set by the web app when a secret is created or its URL changes, and served with the row like any other plaintext metadata. The extension would then render icons straight from the cached snapshot with no request at all. That is a Keepiq change, not an extension one; until it lands, no icon fetching is added under any setting.

## Clipboard

Fill is preferred over copy. Copy auto-clears the clipboard after a configurable delay, default 30 seconds (Bitwarden defaults to off), offered with the same options (10 seconds to 5 minutes, or never). Keepiq's users include non-technical staff, who are better served by a clear they didn't have to turn on. The clear overwrites the clipboard without checking what it holds, since checking needs the `clipboardRead` permission and its install warning.

## Consequences

- Unlock costs one PBKDF2 derivation at 600 000 iterations, roughly half a second on a laptop. Short timeouts multiply that cost and create pressure to weaken the KDF; 15 minutes is the compromise, and the option list goes no lower than 1 minute for a reason.
- Rotating the master password or the suite in the web app invalidates the extension's cache on next sync. This is expected and cheap.
- Because `PUT /api/v1/secrets/{id}` is a whole-blob, last-write-wins patch for `additionalFields`, the extension re-fetches the item before editing and writes only the fields the user changed.
