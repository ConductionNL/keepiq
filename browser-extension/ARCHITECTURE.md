# Architecture

How the extension is put together. Decisions live in the ADRs under [openspec/architecture/](openspec/architecture/), behaviour in the specs under `openspec/changes/`, and the security rules in [SECURITY.md](SECURITY.md).

## Entrypoints

| Entrypoint | Runs as | Job |
| --- | --- | --- |
| `entrypoints/background.ts` | MV3 service worker (Chrome), MV2 background page (Firefox) | Owns every account, key, request and timer. Routes messages, alarms, idle and popup ports. |
| `entrypoints/popup/` | Toolbar popup, or a pop-out window (`?popout=1&tabId=`) | React UI ([ADR-004](openspec/architecture/adr-004-popup-ui-react.md)). Renders what the background returns; holds decrypted values only while shown. |
| `entrypoints/content.ts` | Every frame of every `http(s)` page | Sends `page_ready`, fills the one credential a fill sends, hosts the inline list and the save bar, reports submitted logins. Holds no vault state. |
| `entrypoints/inline-list/` | Extension page in an iframe under a login field | The inline list, vanilla DOM ([ADR-004](openspec/architecture/adr-004-popup-ui-react.md)). Gets its key from the host, its rows from the background. |
| `entrypoints/notification/` | Extension page in an iframe, a card in the top-right corner of the page | The save and update card, vanilla DOM. Never gets the password. |
| `entrypoints/offscreen/` | Chrome offscreen document | Writes and clears the clipboard for the background, created and closed per use. |

## Modules

- `src/api/`: the Keepiq client, the only code that calls `fetch`. Basic auth on the app password, `credentials: 'omit'`, a 401 that logs the account out ([ADR-003](openspec/architecture/adr-003-keepiq-api-contract.md)). `keepiq-version.ts` holds the minimum Keepiq version and the features of newer ones ([KEEPIQ-VERSIONS.md](KEEPIQ-VERSIONS.md)).
- `src/crypto/`: PBKDF2 key derivation, AES-GCM envelope and RSA-OAEP, byte compatible with the web app (tested against `../tests/vectors/crypto/`). `federated-certificate.ts` verifies a partner recipient's certificate chain, ported from the web app.
- `src/accounts/`:
  - `store.ts`: the account list, behind one write queue.
  - `settings.ts`: every per-account setting with its default and validator, patch writes, and the clamp to the organisation's maximum.
  - `policy.ts`: the organisation's idle maximum and export switch, as last read.
  - `verify.ts`: checks credentials before an account is stored.
  - `normalize-server-url.ts`: turns whatever was typed into a server URL.
- `src/settings/`:
  - `global.ts`: settings that apply before an account is chosen: the theme and the appearance switches.
  - `pick-valid.ts`: keeps the keys of a stored record or patch that pass their validator, for both settings records.
- `src/queue.ts`: one-at-a-time queues, so a read-modify-write never works on a stale read.
- `src/vault/`:
  - `key-store.ts`: where the unlocked key lives ([ADR-002](openspec/architecture/adr-002-key-lifetime-and-vault-cache.md)).
  - `unlock.ts`: unlock by master password or PIN, lock and the suite checks.
  - `pin.ts`: the PIN-wrapped key and its failure count in session storage, and the PIN's seal on disk that brings it back after a restart.
  - `timeout.ts`: the timeout engine and both alarms.
  - `sync.ts` and `store.ts`: sync and the snapshot cache.
  - `match.ts`: `baseDomain()`, the public-suffix-aware base domain every match builds on.
  - `list.ts`: sorting, filtering and the folder tree.
  - `payloads.ts`: parses card, identity and passkey payloads.
  - `fields.ts`: the item form's model, limits and validation; pure, so the popup may use it.
  - `write.ts`: item and folder writes, the only code that encrypts to the user's own key.
  - `share-save.ts`: saves a value change with every other copy of a shared item in one share-save.
  - `verified-partners.ts`: partner certificates a failed save verified, kept in memory for 5 minutes.
  - `write-error.ts`: maps a failed write to the code the popup words.
  - `url.ts`: the one rule for turning an item URL into a link.
  - `icons.ts`: type icons.
- `src/background/`:
  - `router.ts`: account messages.
  - `requests.ts`: vault and popup requests.
  - `broadcast.ts`: background-to-popup notices.
  - `sender.ts`: names the sender page and the kinds it may send.
- `src/autofill/`, the specs in `openspec/changes/ext-autofill/`:
  - `matcher.ts`: the six URI match rules and `candidatesFor()`, the logins for a page in last-used order.
  - `fill.ts`: the fill every surface goes through: frame choice, insecure check, decrypt, `fill.execute`, last used.
  - `frames.ts`: the frames of each tab, from `page_ready` senders.
  - `triggers.ts`: fill on page load and from the `autofill_login` shortcut.
  - `inline-session.ts`: the inline list's session and its `inline.*` requests.
  - `capture-queue.ts`: the pending save or update per tab and the bar's `capture.*` requests.
  - `tab-items.ts`: the item each tab last filled or captured, for a password change form without a username.
  - `fields.ts` (content script): field and group detection, and `fillField()`.
  - `execute.ts` (content script): answers `fill.execute`.
  - `capture-collector.ts` (content script): reports a login the user submitted.
  - `frame-host.ts` (content script): an extension page in an iframe inside a closed shadow root.
  - `inline-host.ts` and `notification-host.ts` (content script): place, guard and close the list and the bar.
  - `visibility.ts` (content script): the visibility guard's checks.
  - `visibility-tracker.ts` (list and bar pages): Chrome's IntersectionObserver v2 check.
  - `default-manager.ts`: takes and returns control of Chrome's password manager and address autofill (`openspec/changes/ext-default-password-manager/`).
- `src/menus.ts`: the "Keepiq" context menu, built and handled in the background.
- `src/last-used.ts`: when each item was last filled.
- `src/messages.ts`: every message type, in one place.
- `src/failure.ts`: `Failure`, the error with an `ErrorCode` that the router turns into `{ ok: false, code }`. Its message is for logs only.
- `src/clipboard.ts`: copy in the popup; write and clear from the background.
- `src/totp/`: RFC 6238 codes, a port of the web app's TOTP module.
- `src/generator/`: a port of the web app's password, passphrase and username generator, tested against `../tests/vectors/generator/`. All modules are safe for the popup, except two background-only ones:
  - `store.ts`: the options and the cached policy.
  - `history.ts`: the session history.
- `src/send/`: Keepiq ephemeral sends, byte compatible with the web app (tested against `../tests/vectors/crypto/send.json`). `expiry.ts` and `payload.ts` are safe for the popup; the rest is background-only:
  - `crypto.ts`: the content key and the AES-256-GCM blobs.
  - `argon2.ts`: the Argon2id key for a send password, through `hash-wasm`.
  - `link.ts`: builds the recipient link.
  - `api.ts`: the send routes.
  - `handlers.ts`: the `send.*` requests.
- `src/browser-action.ts`: hides the `action` / `browserAction` split between MV3 and MV2; `openPopupOrPopout()` falls back to a pop-out window.
- `entrypoints/popup/`, settings:
  - `views/settings/`: one view per Settings section; `timeout.ts` holds the timeout options and durations, `domains.ts` the excluded-domain rules.
  - `hooks/useSettings.ts`: `SettingsProvider` in `<App />` reads the global settings once and applies and mirrors the theme and appearance on every screen.
  - `hooks/useAccountSettings.ts`: the shown account's settings, through `account.settings.get` and `account.settings.set`.
  - `theme.ts`: the `keepiq.theme` and `keepiq.appearance` mirrors that paint the first frame.

## Messages

All types are in `src/messages.ts`. The background answers each sender only its own kinds; see [SECURITY.md](SECURITY.md#pages-and-messages).

| Direction | Kinds | Reply |
| --- | --- | --- |
| Popup → background, account actions | `accounts.*`, `vault.unlock`, `vault.lock`, `vault.lockAll`, `vault.status` | `Result`: `{ ok: true, state }` or `{ ok: false, code }` |
| Popup → background, vault requests | `vault.snapshot`, `vault.sync`, `item.decrypt`, `clipboard.copied`, `popup.popout`, `popup.lastTab.set`, `settings.*`, `account.settings.*`, `pin.*`, `generator.*` | The typed reply in `PopupReplies`; `undefined` when the background failed |
| Popup → background, writes | `item.fetch`, `item.editable`, `item.create`, `item.update`, `item.delete`, `folder.create`, `folder.rename`, `folder.children`, `folder.delete`, `folder.dismissNotice` | `WriteResult`: `{ ok: true, value }` or `{ ok: false, error }`, after the sync that follows a write; `item.fetch`, `item.editable` and `folder.children` reply without one |
| Popup → background, sends | `send.list`, `send.create`, `send.delete` | `WriteResult`, without a sync; a 404 on delete is `ok` |
| Popup → background, fill | `fill.request` | `FillResult`, or `needsInsecureConfirm` to ask first |
| Popup → background, default password manager | `defaultManager.get`, `defaultManager.enable`, `defaultManager.disable` | `DefaultManagerState`; `undefined` when the background failed |
| Background → popup | `vault.changed`, `vault.locked` | None; the popup re-reads |
| Background → offscreen | `offscreen.clearClipboard`, `offscreen.writeClipboard` | Whether it wrote |
| Content → background | `page_ready` (`passwordField`: the top frame has a login form, for fill on page load), `inline.closed`, `capture.submitted`, `capture.reshow` | None |
| Content → background, inline list | `inline.open` | `{ key, height }`, or `null` for no list |
| Background → content, one frame by `frameId` | `fill.confirmInsecure`, `fill.execute`, `inline.verify`, `capture.verify` | `boolean`; `FillExecuteReply` for `fill.execute` |
| Background → content, one frame by `frameId` | `inline.ready`, `inline.refocus`, `inline.close`, `inline.resize`, `capture.show`, `capture.hide`, `capture.focus`, `capture.expand` | None |
| Content → its list or bar iframe, by `postMessage` to the extension origin | `inline.init`, `inline.focus`, `inline.moved`, `capture.init`, `capture.focus`, `capture.moved` | None |
| Inline list → background | `inline.state`, `inline.pick`; `inline.refocus`, `inline.close`, `inline.unlock`, `inline.resize` | `InlineStateReply`, `InlinePickReply`; none for the last four |
| Save bar → background | `capture.state`, `capture.save`, `capture.update`, `capture.dismiss`, `capture.expand` | `NotificationReplies` |

- **Routing by sender page.** `src/background/sender.ts` tells the popup, the list and the bar apart by `sender.url` path, and a content script by its tab and frame; each gets only its own kind set in `src/messages.ts`.
- **Popup port.** The popup holds a `popup` port open. Its disconnect drives the "Immediately" timeout, with a 5-second grace while a pop-out window takes over.
- **Use-only copies.** `item.decrypt` returns only the `login` of a `useOnly` row. Asking for more gets `use_only`; in a batch, the row is left out.
- **Passive requests.** A popup request made in reaction to a broadcast is sent with `passive` (or without `opened`), so it doesn't count as user activity for the timeout.

## Storage

| Key | Area | Holds |
| --- | --- | --- |
| `accounts` | local | Account records, app passwords included |
| `activeAccountId` | local | The active account; heals itself when it points nowhere |
| `settings.<id>` | local | Every account-scoped setting: vault timeout and action, clipboard delay, autofill and notification entries, excluded domains, default item type; kept through a logout |
| `settings.global` | local | Theme, compact mode, animations and quick copy; survives logout |
| `accountPolicy.<id>` | local | The organisation's idle maximum and export switch, from the last successful read; kept through a logout |
| `keepiq.theme` | popup `localStorage` | A mirror of the theme for the first frame, never read by the background |
| `keepiq.appearance` | popup `localStorage` | A mirror of compact mode and animations for the first frame, never read by the background |
| `suite.<id>` | local | The active suite row, private key still wrapped |
| `vaultCache.<id>` | local | The snapshot: secret rows, folders, types, suite id and epoch, counts |
| `folderNotice.<id>` | local | The folder manager's plaintext-names notice was dismissed |
| `generatorOptions.<id>` | local | The generator's options and last sub-tab, as the user set them; the policy applies on read |
| `generatorPolicy.<id>` | local | The organisation policy as the generator reads it, from the last successful sync; gone on logout |
| `vaultCheckedAt.<id>` | local | When the server was last checked |
| `neverLockKey.<id>` | local | The unwrapped key, under the "Never" timeout only |
| `pinSeal.<id>` | local | The PIN, sealed under a key derived from the private key and bound to the account, suite and epoch; survives a restart, gone with `pin.<id>` otherwise |
| `lastUsed.<id>` | local | When each item was last filled, by item id, for the suggestion order, the shortcut and fill on page load; written only while unlocked, dropping ids the vault no longer holds; gone on logout and account removal |
| `privateKeyPkcs8.<id>` | session | The unwrapped key while unlocked |
| `pin.<id>` | session | The key wrapped under the PIN, its suite and epoch, and the wrong PINs in a row; kept through a lock, gone on restart, logout, a changed suite or epoch and the fifth wrong PIN |
| `unlockedAt.<id>`, `lastInteractionAt` | session | Timeout bookkeeping |
| `popup:lastTab` | session | The popup tab to reopen |
| `addAccountDraft` | session | Server URL and username while adding an account |
| `editOpened.<id>` | session | Per edit form, for the 10 newest, keyed by the random `recordId` of the form's fetch: the ciphertext it showed in full, the row's `updatedAt` and the source's `sourceUpdatedAt`; a form's entry goes when its save is accepted, stays after a failed one, and all go when the key is cleared |
| `generatorHistory.<id>` | session | The 50 newest generated values, in plaintext; gone when the key is cleared |
| `inlineSession.<tabId>` | session | The tab's open inline list: its random key, account, frame, frame URL, list frame and offered item ids, no plaintext; gone when the list closes, the next list opens, the tab closes or loads, on lock, logout and account switch |

Where `storage.session` is missing (Firefox below 115), session values live in background memory instead, and the draft is not kept.

Background memory only, lost on an MV3 worker restart:

- **Captures** (`capture-queue.ts`): one pending save or update per tab, password included, for 5 minutes; dropped on tab close, leaving the site, lock, logout and account switch.
- **Tab items** (`tab-items.ts`): the item each tab last filled or captured; forgotten on tab close, lock and account switch.
- **Frame registry** (`frames.ts`): each tab's frame ids and URLs from `page_ready`; forgotten when the tab loads or closes.

## Flows

### Unlock

1. Decrypt the cached suite's envelope with the master password. When there's no cache, or the password fails, fetch the suites once.
2. Import the key once, to catch a corrupt one, then store its bytes with `putKey`.
3. Check again that the account is still logged in and that the stored suite is still the one that was decrypted. Otherwise take the key back.
4. Start a full sync.

A PIN unlock replaces step 1 with unwrapping the session record, with no request while the stored Keepiq version is supported; each attempt is counted before the unwrap.

After a master-password unlock that finds `pinSeal.<id>` but no `pin.<id>`, the seal is opened with the fresh key bytes and the session record rebuilt, after the unlock has replied. A seal that fails or names another suite or epoch is deleted.

### Sync

- **Triggers:** unlock, opening the popup with a missing or stale snapshot, the `vault-sync` alarm every 15 minutes, and "Sync now". One sync runs per account; a later trigger joins the one running.
- **Probe:** the newest row and the total, plus the suite list. When nothing changed and the folder and type lists are under 60 minutes old, the sync only records the check time.
- **Full:**
  1. Fetch the manifest. On a refusal (403 or 428) or 404, at 1000 rows, or when the cached total is 1000 or more, page through the secret list instead.
  2. Handle a two-factor block, then a suite change.
  3. Mark rows on blocked suites.
  4. Write the snapshot in one `set`.
- **Policies:** every sync and every login also read `GET /api/settings/policy` and `GET /api/v1/extension/policy`; a failed or unusable read keeps what is stored.
- **Logout during a sync:** checked after every write, and the writes are undone.

### Writes

- **The popup sends plaintext,** the background encrypts with the cached suite's certificate, once it proved that certificate matches the unlocked key and the server's active suite is still that one.
- **Each write names its account,** and is refused when that is no longer the active one.
- **Shared copies keep their limits** (use-only, read-only, expiring, view-only) in the popup and again in the background. A copy is view-only when `write-context` gives it no `owner`, `write` or `manage` grade; the detail asks with `item.editable` unless Edit is already unavailable, and the edit fetch and every save but a move refuse it.
- **Edit and Clone fetch the item fresh** from the server and open it only when its ciphertext matches the vault cache, after one sync on a mismatch (ADR-002); Edit saves the changed fields with `baseUpdatedAt`, so a newer version is not overwritten, and with its fetch's `recordId`, so the save vouches only for what its own form showed.
- **A changed `key`, `login` or `additionalFields` saves through share-save**, the editor's row and every other copy in one transaction: `write-context` picks the owner path (local copies and partner shares), or the copy-editor path (the source and the other copies). The first call carries empty lists; a 409 `incomplete` names what to encrypt for, at most three rounds. Partners get the typed fields plus what the form's `editOpened` record showed, never a fresh row. Any other edit is a plain `PUT`, after a `write-context` read unless it only moves the item.
- **A refused save writes nothing** and keeps the form open; when the reply to the final call is lost, the server wrote everything or nothing. Nothing about a save outlives the request but verified partner certificates, kept in background memory for 5 minutes and forgotten on lock, logout and account switch, and the server's version check orders concurrent saves. The `item.update` reply carries `shareSave` when the item's detail has a note to show.
- **Every write records** whether the server answered, which drives the same offline flag as sync.
- **A successful write runs a full sync** and replies after it, waiting for a sync that started earlier.

### Send

- **The popup sends plaintext,** the background encrypts it under a fresh content key, wraps that key under Argon2id when a password is set, posts the send and replies with the row and the link.
- **Each request names its account,** and is refused when locked or no longer the active account, like a write; a create checks again after Argon2id.
- **Offline is an outcome:** the background tries the request and answers `offline` when no server answers; the popup disables Create while the account is offline.
- **Nothing is stored:** the content, the content key and the link live in background memory for the request only. The popup keeps the links of sends without a password in `SendProvider` (`hooks/useSends.tsx`), mounted inside `Shell`, so they go on popup close, lock and account switch.
- **Password sends** carry no secret in their link, so `send.list` rebuilds it from the row's `token`.
- **Send from an item** reads the login with `item.decrypt` and opens New send with it.

### Autofill

The behaviour is in `openspec/changes/ext-autofill/`, the threat model in [ADR-002](openspec/architecture/adr-002-key-lifetime-and-vault-cache.md#content-scripts).

- **Fill:** the popup, the context menu, the shortcut, page load and an inline pick all go through `fill()`: it matches the item against each recorded frame's URL, decrypts `login` and `key`, and sends one `fill.execute` per matching frame.
- **Inline list:** the content script asks `inline.open` for a key and posts it to the list page, which loads its rows with `inline.state`; a pick goes to the background, which asks the host frame `inline.verify` before it fills.
- **Save bar:** `capture.submitted` queues a capture; the background sends `capture.show` with a fresh key to the top frame, and the bar loads its state with `capture.state` and answers with `capture.save`, `capture.update` or `capture.dismiss`; the background asks the host `capture.verify` for all but Close.

### Timeout

- `enforce()` runs on the `vault-timeout` alarm and before every popup request, and locks or logs out each account past its timeout.
- The timeout is read through `effectiveSettings`, capped at the organisation's stored maximum; Immediately and On system lock also lock at that maximum.
- `syncAlarm()` keeps both alarms only while something is unlocked.

### Lock and logout

- **Lock** clears the key.
- **Log out** marks the account first, then purges. A sync or unlock that is still running sees the mark and undoes its own writes.
- **Account-list changes** queue through `mutateAccounts`, so none of them works on a stale read.

## Translations

The specs are in `openspec/changes/ext-i18n/`.

- **Catalogs:** `locales/<lang>.yml`, English the source, built into `_locales/` by `@wxt-dev/i18n`. Each names its own `language`.
- **Language:** the browser's UI language picks the catalog, with English as the fallback. There is no picker.
- **Use:** `i18n.t('group.key', { name })` from `#i18n`. Helpers for a link inside a sentence, field actions and formatting are in `entrypoints/popup/i18n.tsx`.
- **Codes, not text:** the background sends error, notice and blocked-reason codes; `entrypoints/popup/errors.ts` words them. A blocked row stores its reason as a code too.
- **Formatting:** dates, relative times, numbers and `<html lang>` use the catalog's `language`, not the browser's.
- **Guards:** `locales/locales.test.ts` checks keys and placeholders across catalogs; lint fails on hard-coded text in popup components.
- **Adding a language:** see the [README](README.md#adding-a-language).

## Testing

- Every module and popup component has a `*.test.ts(x)` next to it, run by vitest on WXT's in-memory `browser`.
- Popup tests use happy-dom and the helpers in `entrypoints/popup/testing.ts`.
- Popup tests (`*.test.tsx`) run twice: project `popup-prod` as built, `popup-dev` under StrictMode as `npm run dev` renders; count mount effects with `mounts()`.
- Tests render in English from the real catalogs; `setLocale('nl')` from `src/testing/i18n.ts` switches one to Dutch.
- `test-site/` holds mock pages for manual checks, one per fill and capture case.
- `e2e/` drives the inline list and the save bar of the built Chrome extension on the test site with Playwright, for what only a real browser shows (Chrome's occlusion report, the top layer, modals); it seeds a fake unlocked account from the test vectors. Playwright cannot load extensions into Firefox.
- The commands are in the [README](README.md#checks--builds). Read [WXT-AND-BROWSERS.md](WXT-AND-BROWSERS.md) before touching `entrypoints/`.
