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

- `src/api/`: the Keepiq client, the only code that calls `fetch`. Basic auth on the app password, `credentials: 'omit'`, a 401 that logs the account out ([ADR-003](openspec/architecture/adr-003-keepiq-api-contract.md)).
- `src/crypto/`: PBKDF2 key derivation, AES-GCM envelope and RSA-OAEP, byte compatible with the web app (tested against `../tests/vectors/crypto/`).
- `src/accounts/`:
  - `store.ts`: the account list, behind one write queue.
  - `settings.ts`: the per-account timeout settings and the policy clamp.
  - `verify.ts`: checks credentials before an account is stored.
  - `normalize-server-url.ts`: turns whatever was typed into a server URL.
- `src/vault/`:
  - `key-store.ts`: where the unlocked key lives ([ADR-002](openspec/architecture/adr-002-key-lifetime-and-vault-cache.md)).
  - `unlock.ts`: unlock, lock and the suite checks.
  - `timeout.ts`: the timeout engine and both alarms.
  - `sync.ts` and `store.ts`: sync and the snapshot cache.
  - `match.ts`: base-domain matching for suggestions.
  - `list.ts`: sorting, filtering and the folder tree.
  - `payloads.ts`: parses card, identity and passkey payloads.
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
| Popup → background, vault requests | `vault.snapshot`, `vault.sync`, `item.decrypt`, `clipboard.copied`, `popup.popout`, `popup.lastTab.set` | The typed reply in `PopupReplies`; `undefined` when the background failed |
| Background → popup | `vault.changed`, `vault.locked` | None; the popup re-reads |
| Background → offscreen | `offscreen.clearClipboard` | Whether it cleared |
| Content → background | `page_ready` | None |

- **Popup port.** The popup holds a `popup` port open. Its disconnect drives the "Immediately" timeout, with a 5-second grace while a pop-out window takes over.
- **Passive requests.** A popup request made in reaction to a broadcast is sent with `passive` (or without `opened`), so it doesn't count as user activity for the timeout.

## Storage

| Key | Area | Holds |
| --- | --- | --- |
| `accounts` | local | Account records, app passwords included |
| `activeAccountId` | local | The active account; heals itself when it points nowhere |
| `settings.<id>` | local | Vault timeout and action |
| `suite.<id>` | local | The active suite row, private key still wrapped |
| `vaultCache.<id>` | local | The snapshot: secret rows, folders, types, suite id and epoch, counts |
| `vaultCheckedAt.<id>` | local | When the server was last checked |
| `neverLockKey.<id>` | local | The unwrapped key, under the "Never" timeout only |
| `privateKeyPkcs8.<id>` | session | The unwrapped key while unlocked |
| `unlockedAt.<id>`, `lastInteractionAt` | session | Timeout bookkeeping |
| `popup:lastTab` | session | The popup tab to reopen |
| `addAccountDraft` | session | Server URL and username while adding an account |

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
  1. Fetch the manifest. On 403 or 404, at 1000 rows, or when the cached total is 1000 or more, page through the secret list instead.
  2. Handle a two-factor block, then a suite change.
  3. Mark rows on blocked suites.
  4. Write the snapshot in one `set`.
- **Logout during a sync:** checked after every write, and the writes are undone.

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
