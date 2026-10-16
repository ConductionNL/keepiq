# Security

How the Keepiq browser extension protects your vault. The reasoning is in [ADR-001](openspec/architecture/adr-001-bitwarden-parity.md) (Bitwarden parity), [ADR-002](openspec/architecture/adr-002-key-lifetime-and-vault-cache.md) (keys and cache) and [ADR-003](openspec/architecture/adr-003-keepiq-api-contract.md) (the API).

For users, the same ground is covered in plain language by the [privacy policy](../docs/browser-extension/privacy.md).

To report a vulnerability, follow the [Keepiq security policy](../SECURITY.md): email security@conduction.nl, not a public issue.

## Encryption

The extension uses the same end-to-end encryption as the Keepiq web app and is byte compatible with it, which is tested against the web app's vectors.

- Your master password never leaves the device. It derives an AES-256-GCM key with PBKDF2-SHA-256 at 600,000 iterations, which unwraps your RSA-4096 private key. The password is then dropped.
- Every secret is encrypted with RSA-OAEP (SHA-256) to your key. The server only ever sees ciphertext.
- All cryptography is WebCrypto. The private key is imported as a non-extractable `CryptoKey` that can only decrypt.

## Where data lives

| Data | Stored in | Removed by |
| --- | --- | --- |
| Master password | Nowhere | — |
| Private key | `storage.session` (memory, cleared on browser restart). Firefox below 115 uses background page memory instead. | Lock, timeout, logout, browser restart, extension reload |
| Decrypted values, unsaved forms | Popup memory while shown, never the value of a use-only copy | Closing the item, the form or the popup |
| Ciphertext of the 10 newest edit forms, with the item's and its source's version | `storage.session`, like the private key | That form's accepted save, or 10 newer entries; lock, timeout, logout, account removal, browser restart, extension reload. Closing the form or popup leaves it. |
| Ciphertext, names, URLs, folders, types | `storage.local` | Logout, account removal, a key change on the server |
| App password | `storage.local` | Logout, account removal, the server answering 401 |
| Account list and settings | `storage.local` | Account removal |

- Nothing derived from the master password is written to disk, with one exception: the **"Never"** timeout, which keeps the private key in `storage.local` so it survives a restart. The settings screen (`ext-settings`) will warn about it when you pick it.
- `storage.session` keeps its default access level, so content scripts cannot read it.

## Locking

- The vault locks after 15 minutes without interaction by default. The other options are Immediately (when the last popup closes), On system lock, On browser restart, Never and a custom number of minutes. The background supports them all; the screen to pick them comes with `ext-settings`.
- The timeout action is Lock (the key goes) or Log out (the app password goes too).
- The timeout is enforced on a once-a-minute alarm and again before every popup request. A sleeping service worker can't serve a vault that should already be locked.
- A timeout can fire up to a minute late, or later while the worker sleeps and the popup stays closed. Nothing can use the vault in that time without going through the check.
- A browser restart always locks, whatever the setting, except under "Never".

## Server and network

- The extension only talks to your own Nextcloud server. It sends no telemetry and doesn't fetch favicons from websites or icon services, and the Firefox manifest declares no data collection.
- Every popup field turns off the browser's spell check, so cloud spelling services, such as Chrome's Enhanced spell check or Edge's Microsoft Editor, never receive vault text.
- Requests go from the background only, with Basic auth on an app password and `credentials: 'omit'`, so browser cookies are never sent.
- Server URLs must be `https`. Plain `http` is allowed only for `localhost`, `127.0.0.1` and `.test` hosts, for development.
- Host access is requested per server, when you add an account. The extension holds no other host permissions.

## Revocation

There is no remote wipe, so these checks stand in for it:

- **App password revoked in Nextcloud:** the next request gets a 401. The extension then purges that account's key, vault cache and app password before doing anything else.
- **Key changed in Keepiq (new master password, rotation):** every sync, and the 15-minute check, compares the active suite's id and key epoch. On a difference, the cache and the key are purged and the account locks with "Your vault key changed".
- **Two-factor vault policy:** when the server withholds the key, the extension drops the cached vault, the cached suite and the key, and the next unlock is refused.
- **Revoked or compromised suites:** rows on those suites are stored metadata-only, shown as blocked, and never decrypted, copied or filled.
- **A logout during a running sync or unlock:** the logout wins. Anything the sync or unlock wrote afterwards is taken back.

## Pages and messages

- The background answers popup messages only from the extension's own pages. Messages from content scripts are ignored, apart from the page-ready signal.
- Content scripts hold no vault state and never see the key. URL matching and decryption stay in the background. The planned autofill will hand a page exactly one credential, after you picked it.
- Content scripts run on every page, as in Bitwarden. That is safe only because of the rule above ([ADR-001](openspec/architecture/adr-001-bitwarden-parity.md), ADR-002).
- The popup gets item metadata and ids. It gets decrypted fields only on request, and ciphertext never. Of a use-only copy it gets only the login; the background refuses its value and additional fields.
- No remote code is loaded, and the popup renders no HTML from vault data.

## Saving

- The popup sends what you typed to the background, which does all the encryption.
- Before it encrypts, the extension checks that the server's active key is still the one it holds, and proves the server's certificate belongs to your unlocked key. Otherwise nothing is written.
- A save goes to the account the popup showed, and is refused when another window switched accounts.
- Edit fetches the item fresh, sends only what changed, and is refused when a newer version was saved elsewhere. Edit and Clone only open what your vault already holds for that item, and a save gives partners the fields you didn't touch only as the form showed them in full, under the type the fetch checked. A swap while the form is open changes nothing partners get; a swap before the form opens is not caught (see Known limits, and [ADR-002](openspec/architecture/adr-002-key-lifetime-and-vault-cache.md#sharing)).
- Shared copies keep their limits: a use-only copy is never shown, copied or changed, a read-only one can only be filed in a folder, and no restricted copy can be cloned.
- Delete moves an item to Keepiq's trash and ends its shares. A folder delete is immediate, and "Delete items too" deletes them for good.

## Shared items

- A changed password, username or field of a shared item is encrypted again, in the background, for every copy the server lists, and saved in one request: the server writes your row, every copy in your organisation and every partner share together, or nothing. It refuses a save over a newer version of the item, or, for a team editor, of the original.
- Recipients in your own organisation are trusted as the server lists them, with the certificate it serves, as in the Keepiq web app. A compromised server could list someone you never shared with, or make up a partner share, and get the password, username and fields of any item whose password, username or fields you edit, shared or not. When the form didn't show every part of an item, a partner share stops the save, so such a server gets only what you changed ([ADR-002](openspec/architecture/adr-002-key-lifetime-and-vault-cache.md#sharing)).
- A recipient at a partner organisation gets nothing until their certificate chains to the partner root your server pinned and names them. Otherwise their share is paused. When their server can't be reached, nothing is saved until you choose to save without them, which pauses their share too.
- Both your server and the partner's are still trusted for partner recipients: the check catches a certificate for the wrong person, an expired one, or one from another partner, not a server that lies.
- The decrypted value lives in background memory for that one request. The popup only learns codes and the partner shares paused or not reached, and forgets them when it closes.

## Clipboard

- Copy happens in the popup, under your click.
- The background overwrites the clipboard when a clear delay runs out: through an offscreen document on Chrome, and from the background page on Firefox.
- The delay is 30 seconds by default, and every copy replaces the pending clear. `ext-settings` will let you change it per account, or set it to Never.
- The clear is unconditional: anything you copy in those 30 seconds is cleared too. Checking first would need the `clipboardRead` permission and its install warning.

## Known limits

- **The app password** is long-lived. It can't decrypt anything, but it does give read and write access to the ciphertext. You can revoke it from Nextcloud's security settings.
- **Names, URLs and folders** are stored in plain text on disk, as the server returns them. They reveal which sites you have accounts on, though not the secrets.
- **While the vault is unlocked,** anyone at the machine can use it, up to the timeout.
- **The recipient list of a shared item** comes from the server, so editing a shared item trusts it (see Shared items).
- **A server that swaps another item's value into this one before you open it for editing** is not caught. The form shows that value as this item's, masked if it is a password or another secret, and saving shares it with whoever the server lists. Fields the form doesn't show are never shared this way.
- **Revocation takes effect on the next contact with the server:** at most the 15-minute sync interval, or sooner when the popup opens.
- **A share that becomes use-only** shows as use-only after the next full sync: within an hour and a quarter, or at once after unlock or Sync now. The server doesn't change the copy's `updatedAt`, so the quick probe misses it.
- **Offline caching turned off by an admin:** the extension still caches the vault on disk. Whether it should keep it in session storage instead is still open.

## Supply chain

- Four runtime dependencies: React, React DOM, `tldts` (the public suffix list, bundled; it makes no requests) and `@wxt-dev/i18n` (a thin wrapper over `browser.i18n`).
- CI runs the tests, typecheck, lint, both builds and `web-ext lint` on every change.
