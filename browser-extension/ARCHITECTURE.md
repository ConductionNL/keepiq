# Architecture

How the extension is put together. Decisions live in the ADRs under [openspec/architecture/](openspec/architecture/), behaviour in the specs under `openspec/changes/`, and the security rules in [SECURITY.md](SECURITY.md).

## Entrypoints

| Entrypoint | Runs as | Job |
| --- | --- | --- |
| `entrypoints/background.ts` | MV3 service worker (Chrome), MV2 background page (Firefox) | Owns every account, key, request and timer. Routes messages, alarms, idle and popup ports. |
| `entrypoints/popup/` | Toolbar popup, or a pop-out window (`?popout=1&tabId=`) | React UI ([ADR-004](openspec/architecture/adr-004-popup-ui-react.md)). Renders what the background returns; holds decrypted values only while shown. |
| `entrypoints/content.ts` | Every `http(s)` page | Sends `page_ready`. Autofill (`ext-autofill`) builds on it. |
| `entrypoints/offscreen/` | Chrome offscreen document | Clears the clipboard, created and closed per use. |

## Modules

- `src/api/`: the Keepiq client, the only code that calls `fetch`. Basic auth on the app password, `credentials: 'omit'`, a 401 that logs the account out ([ADR-003](openspec/architecture/adr-003-keepiq-api-contract.md)). `keepiq-version.ts` holds the minimum Keepiq version and the features of newer ones ([KEEPIQ-VERSIONS.md](KEEPIQ-VERSIONS.md)).
- `src/crypto/`: PBKDF2 key derivation, AES-GCM envelope and RSA-OAEP, byte compatible with the web app (tested against `../tests/vectors/crypto/`). `federated-certificate.ts` verifies a partner recipient's certificate chain, ported from the web app.
- `src/accounts/`:
  - `store.ts`: the account list, behind one write queue.
  - `settings.ts`: the per-account timeout settings and the policy clamp.
  - `verify.ts`: checks credentials before an account is stored.
  - `normalize-server-url.ts`: turns whatever was typed into a server URL.
- `src/settings/global.ts`: settings that apply before an account is chosen, today the theme.
- `src/vault/`:
  - `key-store.ts`: where the unlocked key lives ([ADR-002](openspec/architecture/adr-002-key-lifetime-and-vault-cache.md)).
  - `unlock.ts`: unlock, lock and the suite checks.
  - `timeout.ts`: the timeout engine and both alarms.
  - `sync.ts` and `store.ts`: sync and the snapshot cache.
  - `match.ts`: base-domain matching for suggestions.
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
- `src/messages.ts`: every message type, in one place.
- `src/failure.ts`: `Failure`, the error with an `ErrorCode` that the router turns into `{ ok: false, code }`. Its message is for logs only.
- `src/clipboard.ts`: copy in the popup, clear from the background.
- `src/totp/`: RFC 6238 codes, a port of the web app's TOTP module.
- `src/browser-action.ts`: hides the `action` / `browserAction` split between MV3 and MV2.

## Messages

All types are in `src/messages.ts`. The background only answers extension pages; see [SECURITY.md](SECURITY.md#pages-and-messages).

| Direction | Kinds | Reply |
| --- | --- | --- |
| Popup → background, account actions | `accounts.*`, `vault.unlock`, `vault.lock`, `vault.lockAll`, `vault.status` | `Result`: `{ ok: true, state }` or `{ ok: false, code }` |
| Popup → background, vault requests | `vault.snapshot`, `vault.sync`, `item.decrypt`, `clipboard.copied`, `popup.popout`, `popup.lastTab.set`, `settings.*` | The typed reply in `PopupReplies`; `undefined` when the background failed |
| Popup → background, writes | `item.fetch`, `item.editable`, `item.create`, `item.update`, `item.delete`, `folder.create`, `folder.rename`, `folder.children`, `folder.delete`, `folder.dismissNotice` | `WriteResult`: `{ ok: true, value }` or `{ ok: false, error }`, after the sync that follows a write; `item.fetch`, `item.editable` and `folder.children` reply without one |
| Background → popup | `vault.changed`, `vault.locked` | None; the popup re-reads |
| Background → offscreen | `offscreen.clearClipboard` | Whether it cleared |
| Content → background | `page_ready` | None |

- **Popup port.** The popup holds a `popup` port open. Its disconnect drives the "Immediately" timeout, with a 5-second grace while a pop-out window takes over.
- **Use-only copies.** `item.decrypt` returns only the `login` of a `useOnly` row. Asking for more gets `use_only`; in a batch, the row is left out.
- **Passive requests.** A popup request made in reaction to a broadcast is sent with `passive` (or without `opened`), so it doesn't count as user activity for the timeout.

## Storage

| Key | Area | Holds |
| --- | --- | --- |
| `accounts` | local | Account records, app passwords included |
| `activeAccountId` | local | The active account; heals itself when it points nowhere |
| `settings.<id>` | local | Vault timeout and action |
| `settings.global` | local | The theme; survives logout |
| `keepiq.theme` | popup `localStorage` | A mirror of the theme for the first frame, never read by the background |
| `suite.<id>` | local | The active suite row, private key still wrapped |
| `vaultCache.<id>` | local | The snapshot: secret rows, folders, types, suite id and epoch, counts, the default item type |
| `folderNotice.<id>` | local | The folder manager's plaintext-names notice was dismissed |
| `vaultCheckedAt.<id>` | local | When the server was last checked |
| `neverLockKey.<id>` | local | The unwrapped key, under the "Never" timeout only |
| `privateKeyPkcs8.<id>` | session | The unwrapped key while unlocked |
| `unlockedAt.<id>`, `lastInteractionAt` | session | Timeout bookkeeping |
| `popup:lastTab` | session | The popup tab to reopen |
| `addAccountDraft` | session | Server URL and username while adding an account |
| `editOpened.<id>` | session | Per edit form, for the 10 newest, keyed by the random `recordId` of the form's fetch: the ciphertext it showed in full, the row's `updatedAt` and the source's `sourceUpdatedAt`; a form's entry goes when its save is accepted, stays after a failed one, and all go when the key is cleared |

Where `storage.session` is missing (Firefox below 115), session values live in background memory instead, and the draft is not kept.

## Flows

### Unlock

1. Decrypt the cached suite's envelope with the master password. When there's no cache, or the password fails, fetch the suites once.
2. Import the key once, to catch a corrupt one, then store its bytes with `putKey`.
3. Check again that the account is still logged in and that the stored suite is still the one that was decrypted. Otherwise take the key back.
4. Start a full sync.

### Sync

- **Triggers:** unlock, opening the popup with a missing or stale snapshot, the `vault-sync` alarm every 15 minutes, and "Sync now". One sync runs per account; a later trigger joins the one running.
- **Probe:** the newest row and the total, plus the suite list. When nothing changed and the folder and type lists are under 60 minutes old, the sync only records the check time.
- **Full:**
  1. Fetch the manifest, and the user settings beside it for the default item type. On a refusal (403 or 428) or 404, at 1000 rows, or when the cached total is 1000 or more, page through the secret list instead.
  2. Handle a two-factor block, then a suite change.
  3. Mark rows on blocked suites.
  4. Write the snapshot in one `set`.
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

### Timeout

- `enforce()` runs on the `vault-timeout` alarm and before every popup request, and locks or logs out each account past its timeout.
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
- Tests render in English from the real catalogs; `setLocale('nl')` from `src/testing/i18n.ts` switches one to Dutch.
- `test-site/` holds mock pages for manual checks, one per fill and capture case.
- The commands are in the [README](README.md#checks--builds). Read [WXT-AND-BROWSERS.md](WXT-AND-BROWSERS.md) before touching `entrypoints/`.
