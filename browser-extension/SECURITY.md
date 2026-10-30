# Security

How the Keepiq browser extension protects your vault. The reasoning is in [ADR-001](openspec/architecture/adr-001-bitwarden-parity.md) (Bitwarden parity), [ADR-002](openspec/architecture/adr-002-key-lifetime-and-vault-cache.md) (keys and cache) and [ADR-003](openspec/architecture/adr-003-keepiq-api-contract.md) (the API).

For users, the same ground is covered in plain language by the [privacy policy](../docs/browser-extension/privacy.md).

To report a vulnerability, follow the [Keepiq security policy](../SECURITY.md): email security@conduction.nl, not a public issue.

## Encryption

The extension uses the same end-to-end encryption as the Keepiq web app and is byte compatible with it, which is tested against the web app's vectors.

- Your master password never leaves the device. It derives an AES-256-GCM key with PBKDF2-SHA-256 at 600,000 iterations, which unwraps your RSA-4096 private key. The password is then dropped.
- Every secret is encrypted with RSA-OAEP (SHA-256) to your key. The server only ever sees ciphertext.
- All cryptography is WebCrypto, except Argon2id. The private key is imported as a non-extractable `CryptoKey` that can only decrypt.
- A send is encrypted with AES-256-GCM under a fresh content key. Without a password the key is only in the link's `#k=` fragment, which browsers never send to the server; with one, the key is wrapped under Argon2id (64 MiB, 3 iterations, parallelism 1) of the password, as in the web app.
- Argon2id runs in WebAssembly from `hash-wasm`, bundled. The extension pages' CSP adds `'wasm-unsafe-eval'` for it, which allows compiling WebAssembly, not `eval` of scripts.

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
| Generated values, the 50 newest | `storage.session`, like the private key | Clear in the History view; lock, timeout, logout, account removal, browser restart, extension reload |
| Values generated while locked | `storage.session`, encrypted to the account's public key | The first history read or recorded value after the unlock, which moves them into the history; a lock after that unlock, other than for a key change on the server; logout, account removal, browser restart, extension reload |
| Generator options | `storage.local` | Account removal |
| Organisation password policy | `storage.local` | Logout, account removal |
| Send content, content key, send password | Background memory for the one request; popup memory while the form is open | The request's reply; leaving the form or closing the popup |
| Link of a send without a password | Popup memory, from creation | Closing the popup, lock, timeout, account switch |
| Link of a password send | Popup memory with the list; holds no secret, the background rebuilds it from the token the server lists | Closing the popup, lock, timeout, account switch |
| The list of sends | Popup memory, fetched per popup session | Closing the popup, lock, timeout, account switch |
| The credential of a fill | Background memory for the fill; the page's fields once filled | The fill's end; the content script drops it after writing the fields |
| A login's TOTP seed and its code | Background memory for one fill, list refresh or menu click; popup memory while the detail view shows the code, never the seed; the code also in the page's code field once filled | That handler's end or the view closing; the content script drops a code it has no code field for |
| When each item was last filled (`lastUsed.<id>`), no other history | `storage.local` | Logout, account removal |
| Inline list session: a random key, the tab, frame and frame URL, the offered item ids; no plaintext | `storage.session`, like the private key | The list closing, the next list in the tab, tab close or navigation, lock, timeout, logout, account switch, browser restart, extension reload |
| Names, usernames and verification codes in the inline list | The list page's memory while shown | The list closing |
| Which account's code is on the clipboard (`clipboardCode`), never the code | `storage.session`, like the private key | The clear, any other copy, that account's lock or logout, browser restart, extension reload |
| How many logins match each tab, shown on the toolbar badge while unlocked | Never stored; the browser holds the painted badge per tab | Lock, timeout, logout, switching to an account that isn't unlocked, turning the counter off, the tab navigating, browser restart, extension reload |
| A submitted login waiting for Save or Update, password included | Background memory only, never storage | The answer, 5 minutes, tab close, leaving the site, lock, timeout, logout, account switch, an MV3 worker restart |

- Nothing derived from the master password is written to disk, with one exception: the **"Never"** timeout, which keeps the private key in `storage.local` so it survives a restart. Settings warns about it when you pick it.
- `storage.session` keeps its default access level, so content scripts cannot read it.

## Locking

- The vault locks after 15 minutes without interaction by default. The other options are Immediately (when the last popup closes), On system lock, On browser restart, Never and a custom number of minutes, picked in Settings.
- The timeout action is Lock (the key goes) or Log out (the app password goes too).
- The timeout is enforced on a once-a-minute alarm and again before every popup request. A sleeping service worker can't serve a vault that should already be locked.
- A timeout can fire up to a minute late, or later while the worker sleeps and the popup stays closed. Nothing can use the vault in that time without going through the check.
- A browser restart always locks, whatever the setting, except under "Never".
- A lock or logout empties the toolbar badge on every tab. A locked vault never shows a count, though the snapshot's plaintext URLs could produce one.
- A lock or logout clears a verification code the extension copied for that account at once, whatever the clear delay.

## Server and network

- The extension only talks to your own Nextcloud server. It sends no telemetry and doesn't fetch favicons from websites or icon services, and the Firefox manifest declares no data collection.
- Every popup field turns off the browser's spell check, so cloud spelling services, such as Chrome's Enhanced spell check or Edge's Microsoft Editor, never receive vault text.
- Requests go from the background only, with Basic auth on an app password and `credentials: 'omit'`, so browser cookies are never sent.
- Server URLs must be `https`. Plain `http` is allowed only for `localhost`, `127.0.0.1` and `.test` hosts, for development.
- Host access is requested per server, when you add an account. The extension holds no other host permissions.
- On Chrome, `privacy` is requested only when you turn on "Make Keepiq your default password manager", and given back when you turn it off.

## Revocation

There is no remote wipe, so these checks stand in for it:

- **App password revoked in Nextcloud:** the next request gets a 401. The extension then purges that account's key, vault cache and app password before doing anything else.
- **Key changed in Keepiq (new master password, rotation):** every sync, and the 15-minute check, compares the active suite's id and key epoch. On a difference, the cache and the key are purged and the account locks with "Your vault key changed".
- **Two-factor vault policy:** when the server withholds the key, the extension drops the cached vault, the cached suite and the key, and the next unlock is refused.
- **Revoked or compromised suites:** rows on those suites are stored metadata-only, shown as blocked, and never decrypted, copied or filled.
- **A logout during a running sync or unlock:** the logout wins. Anything the sync or unlock wrote afterwards is taken back.

## Pages and messages

- The background answers each sender only its own message kinds (`src/background/sender.ts`): popup kinds only from `/popup.html`, the save bar's only from `/notification.html`, the inline list's only from `/inline-list.html`, and from content scripts only the page-ready signal, opening and closing the list, a submitted login, and a request to show the bar again.
- Tab, frame and URL come from the browser's `sender`, never from the message.
- Content scripts hold no vault state and never see the key. URL matching and decryption stay in the background. A fill hands the page exactly one credential, after you picked it, and only to frames whose address matches the item.
- Content scripts run on every page, as in Bitwarden. That is safe only because of the rule above ([ADR-001](openspec/architecture/adr-001-bitwarden-parity.md), ADR-002).
- The popup gets item metadata and ids. It gets decrypted fields only on request, and ciphertext never. Of a use-only copy it gets only the login; the background refuses its value and additional fields.
- No remote code is loaded, and no extension page renders HTML from vault data.

## In-page UI

The inline list and the save bar show inside web pages. The reasoning is in [ADR-002](openspec/architecture/adr-002-key-lifetime-and-vault-cache.md#content-scripts), the details in `openspec/changes/ext-autofill/design.md`.

- **Isolated:** each is an extension page in an iframe inside a closed shadow root; the page can neither read it nor restyle it, and any change the content script didn't make closes it.
- **Key handshake:** the content script posts a random key to its own iframe at the extension origin; the background answers only the first frame of that page, tab and frame to present it, so a copy the page frames itself gets nothing.
- **What the page learns:** the content script never gets names or usernames; the list's height is one row when the vault is locked, when no login matches and when one does, so one row doesn't tell them apart; two to five rows show that many matches (five for more) and so that the vault is unlocked, which is accepted as useless to a site; the not-visible notice and the insecure-site question size to their text, but only after the user picked a row; and the save card's size depends on the window only.
- **Visibility guard:** a pick, Save, Update or Never counts only when the list or bar stayed untouched and on top for the 500 ms before the press, and is what the press point hits. Otherwise it is refused, and the list or bar says so.
- **Frames:** no inline list in a frame with a cross-origin ancestor, on either browser, since the extension couldn't check that ancestor's styling.
- **Chrome** adds IntersectionObserver v2, which also sees overlays that let clicks through.
- **Firefox** has no such report, so the guard samples every frame and is best effort: an overlay that lets clicks through, or a style changed only between samples, goes unnoticed. A `filter` on `<html>`, such as "Invert the page" or Dark Reader's filter mode, refuses every pick there.
- **Assistive styling:** a zoom extension's `zoom` on `<html>` refuses every pick on both browsers.
- **Trusted submissions:** a login is offered for saving only after a trusted submit with user activation, at most once per activation, since whether a bar appears tells the page whether that login is saved.
- **The bar never gets the password,** which stays in background memory until you answer.

## Saving

- The popup sends what you typed to the background, which does all the encryption.
- Before it encrypts, the extension checks that the server's active key is still the one it holds, and proves the server's certificate belongs to your unlocked key. Otherwise nothing is written.
- A save goes to the account the popup showed, and is refused when another window switched accounts.
- Edit fetches the item fresh, sends only what changed, and is refused when a newer version was saved elsewhere. Edit and Clone only open what your vault already holds for that item, and a save gives partners the fields you didn't touch only as the form showed them in full, under the type the fetch checked. A swap while the form is open changes nothing partners get; a swap before the form opens is not caught (see Known limits, and [ADR-002](openspec/architecture/adr-002-key-lifetime-and-vault-cache.md#sharing)).
- Shared copies keep their limits: a use-only copy is never shown, copied or changed, a read-only one can only be filed in a folder, one shared to view only can't be changed but can be cloned or filed, and a use-only, read-only or expiring copy can't be cloned.
- Delete moves an item to Keepiq's trash and ends its shares. A folder delete is immediate, and "Delete items too" deletes them for good.

## Shared items

- A changed password, username or field of a shared item is encrypted again, in the background, for every copy the server lists, and saved in one request: the server writes your row, every copy in your organisation and every partner share together, or nothing. It refuses a save over a newer version of the item, or, for a team editor, of the original.
- Recipients in your own organisation are trusted as the server lists them, with the certificate it serves, as in the Keepiq web app. A compromised server could list someone you never shared with, or make up a partner share, and get the password, username and fields of any item whose password, username or fields you edit, shared or not. When the form didn't show every part of an item, a partner share stops the save, so such a server gets only what you changed ([ADR-002](openspec/architecture/adr-002-key-lifetime-and-vault-cache.md#sharing)).
- A recipient at a partner organisation gets nothing until their certificate chains to the partner root your server pinned and names them. Otherwise their share is paused. When their server can't be reached, nothing is saved until you choose to save without them, which pauses their share too.
- Both your server and the partner's are still trusted for partner recipients: the check catches a certificate for the wrong person, an expired one, or one from another partner, not a server that lies.
- The decrypted value lives in background memory for that one request. The popup only learns codes and the partner shares paused or not reached, and forgets them when it closes.

## Clipboard

- Copy happens in the popup, under your click, or from the context menu and after a fill of a login with a verification code, where the background writes it.
- The background writes and clears the clipboard through an offscreen document on Chrome, and from the background page on Firefox.
- On Chrome the background reaches the offscreen document with a runtime message, so `offscreen.writeClipboard` and the copied value reach every open extension view; only the offscreen document acts on it.
- The delay is 30 seconds by default, and every copy replaces the pending clear. Settings changes it per account, or sets it to Never.
- The clear is unconditional: anything you copy in those 30 seconds is cleared too. Checking first would need the `clipboardRead` permission and its install warning.

## Known limits

- **The app password** is long-lived. It can't decrypt anything, but it does give read and write access to the ciphertext. You can revoke it from Nextcloud's security settings.
- **Names, URLs and folders** are stored in plain text on disk, as the server returns them. They reveal which sites you have accounts on, though not the secrets.
- **While the vault is unlocked,** anyone at the machine can use it, up to the timeout.
- **The recipient list of a shared item** comes from the server, so editing a shared item trusts it (see Shared items).
- **A server that swaps another item's value into this one before you open it for editing** is not caught. The form shows that value as this item's, masked if it is a password or another secret, and saving shares it with whoever the server lists. Fields the form doesn't show are never shared this way.
- **Revocation takes effect on the next contact with the server:** at most the 15-minute sync interval, or sooner when the popup opens.
- **A share that becomes use-only** shows as use-only after the next full sync: within an hour and a quarter, or at once after unlock or Sync now. The server doesn't change the copy's `updatedAt`, so the quick probe misses it.
- **A send link without a password** is the secret itself: anyone who gets it can open the send, up to its view count. The server burns a password send after five wrong passwords.
- **Offline caching turned off by an admin:** the extension still caches the vault on disk. Whether it should keep it in session storage instead is still open.

## Supply chain

- Five runtime dependencies: React, React DOM, `tldts` (the public suffix list, bundled; it makes no requests), `@wxt-dev/i18n` (a thin wrapper over `browser.i18n`) and `hash-wasm` (Argon2id for send passwords; its WebAssembly is bundled, nothing is fetched).
- CI runs the tests, typecheck, lint, both builds and `web-ext lint` on every change.
