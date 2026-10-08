## Context

The repo is the WXT starter: a background that owns one flag, a stateless popup, a content script handshake and typed message unions in `src/messages.ts`. This change replaces the flag with the real foundation: accounts, the Keepiq API client, the crypto module and the unlock/lock state machine. Every later change in the chain consumes these modules and adds messages to the same unions.

Constraints that shape the design: the background is the only place with host permissions and the only owner of state (WXT-AND-BROWSERS.md); Chrome runs an MV3 service worker that sleeps after roughly 30 seconds, Firefox runs a persistent MV2 page; key material may live in `storage.session` or memory only (ADR-002); the server has no session, so the lock is entirely client-side (ADR-003).

## Goals / Non-Goals

**Goals:**
- One account store, one API client, one key store and one lock engine, each in its own module and reachable from the popup only through typed messages.
- Byte-compatible crypto with the Keepiq web app (ADR-003) so downstream changes decrypt and encrypt without re-deriving formats.
- Bitwarden's login, unlock, account switcher and vault timeout behaviour (ADR-001) on Keepiq's two-credential model.
- Both browser targets build and load with no `chrome.*` in executable code.

**Non-Goals:**
- Fetching, caching or rendering secrets, folders or types (ext-vault-browse owns `vaultCache.<accountId>`).
- The settings screen, the timeout option picker UI and PIN unlock (ext-settings).
- Suite rotation, revocation or compromise recovery (needs a signed vault-key proof, ADR-003; the extension links to the web app).
- Autofill, the badge and any content-script behaviour beyond the existing `page_ready` handshake.

## Decisions

- **Background owns all account and key state; the popup is a renderer.** The popup sends a message, the background mutates storage and returns a full `PopupState`. Alternative: popup reads `storage.local` directly, rejected because two writers race and the key must never be readable from the popup.
- **Account status is derived, not stored.** `appPassword === null` gives "Logged out", a key present in the key store gives "Unlocked", otherwise "Locked". Alternative: a stored `status` field, rejected because it can drift from the truth after a worker restart.
- **Manual "Log out" removes the account; a 401 or the timeout action "Log out" keeps the identity record.** This matches Bitwarden's switcher and lets the user re-enter only the app password after a revocation. Alternative: one behaviour for both, rejected because a revoked app password is the common case and retyping the server URL is friction.
- **The popup calls `browser.permissions.request` itself, inside the click handler.** Optional permissions need a user gesture, which the background never has. The background then verifies and stores. Alternative: `host_permissions: ['<all_urls>']`, rejected for store review reasons (CLAUDE.md open decision).
- **Verification order is identity first, suites second.** `GET /ocs/v2.php/cloud/user` (`OCS\Provisioning`, Nextcloud core) separates "not Nextcloud" and "bad app password" from Keepiq errors; `GET /api/v1/suites` (`EncryptionSuiteController::index`) separates "Keepiq missing" (404 without a JSON `message`) from "no active suite" (empty or no `active` row).
- **The suite row is cached by this change under `suite.<accountId>`, separate from `vaultCache.<accountId>`.** Unlock needs it offline and ext-vault-browse does not exist yet. Alternative: wait for the manifest cache, rejected because unlock would then need the network.
- **Key store is a shim with two backends.** `src/vault/key-store.ts` writes PKCS#8 bytes to `browser.storage.session` when it exists and to a module-level `Map` otherwise (Firefox below 115, persistent MV2 page). It re-imports to a non-extractable `CryptoKey` on first use per worker generation and memoises it. Alternative: raise `strict_min_version` to 115, deferred to a later decision.
- **Timeout engine uses one `browser.alarms` alarm plus a popup port.** A `vault-timeout` alarm with `periodInMinutes: 1` runs while any account is unlocked and compares `lastInteractionAt` against each account's timeout. The popup opens a `runtime.connect` port on load; `onDisconnect` implements "Immediately". `browser.idle.onStateChanged` with state `locked` implements "On system lock". `vault.status` runs the same check first so a sleeping worker cannot extend a session. Alternative: `setTimeout` in the worker, rejected because MV3 kills it.
- **Timeout policy is one constant object** (`TIMEOUT_POLICY = { maxMinutes, forcedAction }` in `src/vault/timeout.ts`) so an admin clamp later touches one place (ADR-002).
- **"Never" writes the same PKCS#8 base64 to `storage.local` under `neverLockKey.<accountId>`** and the key store reads it back after a restart. Every lock path deletes it. Alternative: refuse "Never", rejected for Bitwarden parity.
- **Errors cross the message boundary as a discriminated `code`, not as thrown errors.** Every request/response message resolves to `{ ok: true, state } | { ok: false, code, message }` so the popup can map codes to copy and `sendMessage` never rejects on a domain error.
- **The popup is React with TypeScript through `@wxt-dev/module-react` (ADR-004).** `main.tsx` mounts `<App />`, which picks a view from `state.screen`; views compose components, and hooks are the only bridge to the background (`useMessage` wraps `browser.runtime.sendMessage` with the typed envelopes, `usePopupState` holds the background-owned `PopupState`). No component imports `src/api` or `src/crypto`, and the background and content scripts stay plain TypeScript. This change lands the dependencies once for the whole chain. Alternative: the template's vanilla view modules, rejected by ADR-004 because shared stateful components across seven changes need one component model.
- **Scaffold toggle removed.** `get_state`, `set_enabled`, `enabled_changed` and the badge counter go; `page_ready` stays for ext-autofill. Keeping dead UI in the real popup costs more than the scaffold is worth.
- **Crypto tests use vitest, the one automated test in the repo.** Envelope layout, chunk framing and round trips are cheap to test and expensive to debug against a live server.

## Module layout

New:

- `src/api/types.ts`: suite, secret (normal and blocked), folder, type, paginated envelope, manifest, user settings, OCS user.
- `src/api/client.ts`: `createClient(account)`, `request()`, error classes `ApiError`, `SessionRevoked`, `VaultWriteLocked`, `Offline`, `KeepiqNotInstalled`, identity helpers `fetchIdentity`, `fetchAvatarDataUrl`, and an `onUnauthorized` hook the account store registers.
- `src/crypto/base64.ts`, `src/crypto/envelope.ts` (decode and version check), `src/crypto/kdf.ts` (PBKDF2 to AES-GCM key), `src/crypto/rsa.ts` (PKCS#8 import, X.509 SPKI extraction, chunked RSA-OAEP decrypt and encrypt), `src/crypto/index.ts`.
- `src/crypto/*.test.ts`: vitest round-trip and layout tests.
- `src/accounts/normalize-origin.ts`: URL to origin, https rule with the dev-host allow list.
- `src/accounts/store.ts`: `storage.local` schema, add, reauthenticate, remove, removeAll, setActive, purge helpers, the 5-account limit and duplicate check.
- `src/accounts/verify.ts`: identity then suites, error mapping, avatar fetch.
- `src/vault/key-store.ts`: session or memory backend, re-import, `neverLockKey` handling.
- `src/vault/unlock.ts`: `unlock(accountId, method)`, suite fetch when uncached, epoch check, `lock`, `lockAll`, `logoutForTimeout`.
- `src/vault/timeout.ts`: settings defaults, `TIMEOUT_POLICY`, alarm, idle listener, popup port tracking.
- `entrypoints/popup/main.tsx`: mounts `<App />` and opens the `popup` interaction port.
- `entrypoints/popup/App.tsx`: reads `usePopupState()` and renders the view for `state.screen` inside the `Header`.
- `entrypoints/popup/hooks/useMessage.ts`: typed wrapper around `browser.runtime.sendMessage` for `PopupToBackground`, resolving to `Result`; the single `unknown` cast in the popup.
- `entrypoints/popup/hooks/usePopupState.ts`: fetches `vault.status` on mount, exposes `state`, `refresh()` and `dispatch(message)` that replaces the state with the returned one.
- `entrypoints/popup/components/Header.tsx` (title, avatar slot opening the switcher), `Button.tsx`, `TextField.tsx` (label, error, show/hide toggle for `type="password"`), `ErrorBanner.tsx`, `Avatar.tsx` (data URL or initials disc).
- `entrypoints/popup/views/AddAccount.tsx`, `LogInAgain.tsx`, `Unlock.tsx`, `AccountSwitcher.tsx`, `Unlocked.tsx` (placeholder with identity and Lock, replaced by ext-vault-browse).

Edited:

- `wxt.config.ts`: `modules: ['@wxt-dev/module-react']`; `permissions` add `alarms`, `idle`; `optional_host_permissions` (Chrome) or `optional_permissions` (Firefox) for `https://*/*` and `http://*/*`; `strict_min_version` stays `109.0`.
- `eslint.config.mjs`: `eslint-plugin-react-hooks` recommended rules for `entrypoints/**/*.tsx`.
- `src/messages.ts`: new unions and state types below; scaffold messages removed.
- `entrypoints/background.ts`: message router, alarm and idle listeners, port listener, `onUnauthorized` wiring.
- `entrypoints/popup/index.html` (a single `#root` and the `main.tsx` script), `popup.css` (styles for form fields, buttons, avatar disc, list rows, switcher panel and error text, same token approach). `main.ts` is deleted in favour of `main.tsx`.
- `package.json`: `react`, `react-dom` dependencies; `@types/react`, `@types/react-dom`, `@wxt-dev/module-react`, `eslint-plugin-react-hooks`, `vitest` dev dependencies; a `test` script.

## Message contract

```ts
export type AccountStatus = 'unlocked' | 'locked' | 'logged_out'

export interface AccountSummary {
	id: string
	origin: string
	host: string
	uid: string
	displayName: string
	avatarDataUrl: string | null
	status: AccountStatus
	active: boolean
}

export type PopupScreen = 'add_account' | 'reauthenticate' | 'unlock' | 'unlocked'

export interface PopupState {
	screen: PopupScreen
	accounts: AccountSummary[]
	active: AccountSummary | null
	/** One-shot banner, e.g. "Session revoked, please log in again". */
	notice: string | null
	canAddAccount: boolean
}

export type ErrorCode =
	| 'insecure_url' | 'invalid_url' | 'permission_denied' | 'unreachable' | 'not_nextcloud'
	| 'unauthorized' | 'keepiq_missing' | 'no_active_suite' | 'duplicate' | 'limit_reached'
	| 'invalid_master_password' | 'offline_no_cache' | 'session_revoked' | 'write_locked' | 'unknown'

export type Result = { ok: true; state: PopupState } | { ok: false; code: ErrorCode; message: string }

/** Popup → background. Request/response, every arm resolves to `Result`. */
export type PopupToBackground =
	| { kind: 'accounts.list' }
	| { kind: 'accounts.add'; serverUrl: string; username: string; appPassword: string }
	| { kind: 'accounts.reauthenticate'; accountId: string; appPassword: string }
	| { kind: 'accounts.remove'; accountId: string }
	| { kind: 'accounts.removeAll' }
	| { kind: 'accounts.switch'; accountId: string }
	| { kind: 'vault.unlock'; accountId: string; method: { type: 'masterPassword'; masterPassword: string } }
	| { kind: 'vault.lock'; accountId: string }
	| { kind: 'vault.lockAll' }
	| { kind: 'vault.status' }
```

`accounts.list` resolves to the same `Result` so the switcher can refresh without a second type. The popup port is named `'popup'`; it carries no payload, only connect and disconnect.

Storage keys in `storage.local`: `accounts` (record by id), `activeAccountId`, `settings.<id>`, `suite.<id>`, `vaultCache.<id>` (ext-vault-browse), `neverLockKey.<id>`. In `storage.session`: `privateKeyPkcs8.<id>`, `unlockedAt.<id>`, `lastInteractionAt`.

## Browser differences

- `storage.session` is absent on Firefox below 115: `key-store.ts` falls back to memory. Both branches expose the same async API.
- Optional host permissions: `optional_host_permissions` on Chrome MV3, `optional_permissions` with origin patterns on Firefox MV2. `wxt.config.ts` branches on the `browser` argument. `browser.permissions.request({ origins })` is identical at runtime.
- `browser.alarms` and `browser.idle` exist on both. Chrome MV3 enforces a 30 second minimum period; the engine uses 1 minute.
- The MV3 worker sleeps: every listener re-reads state from storage and the `CryptoKey` memo is rebuilt from bytes. Nothing relies on module state surviving between events except the Firefox memory backend, which runs on a persistent page.
- `browser.action` vs `browser.browserAction`: already shimmed in `src/browser-action.ts`; this change does not touch the badge.

## Risks / Trade-offs

- [Raw PKCS#8 bytes sit in `storage.session`] → Accepted per ADR-002; `setAccessLevel` is left at its default so content scripts cannot read it, and every lock path deletes the key.
- [`permissions.request` from the popup closes the popup on some Chrome versions] → The form values are React state in `AddAccount`; they are mirrored to the popup's `sessionStorage` on change so a reopened popup restores them for the retry.
- [PBKDF2 at 600 000 iterations takes about half a second] → Unlock button shows a busy state; short default timeouts are avoided (ADR-002).
- [A 1 minute alarm can leave a vault unlocked up to 59 seconds past its timeout] → `vault.status` re-checks on every popup open, so the popup never renders an expired session.
- [Nextcloud returns HTML for unknown routes] → The client treats a 404 without a JSON `message` on a Keepiq route as `KeepiqNotInstalled` and everything else as `ApiError`.
- [App password in `storage.local` is readable by anyone with profile access] → Accepted per ADR-002; revoking the app password in Nextcloud is the remote kill switch and 401 purges everything.
- [Two accounts on the same origin share one host permission] → Removal never revokes the permission, so removing one account cannot break the other.
- [`crypto.subtle` is missing on `http` origins in some contexts] → The extension pages are `chrome-extension://` and `moz-extension://`, which are secure contexts; only the server URL may be `http` for dev hosts.

## Open Questions

- Whether the `http` allow list (`localhost`, `127.0.0.1`, `*.test`, `*.local`) should be a build-time setting instead of a code constant.
- Whether "On system lock" stays in the option list; Bitwarden offers it and `browser.idle` makes it cheap, but ADR-002's list omits it.
- Whether a "Logged out" account should expire and be removed automatically after some time, as Bitwarden does not.
- Whether to raise `strict_min_version` to 115 and drop the memory backend once Firefox usage data exists.
- The content script `matches` allow list (CLAUDE.md open decision) is untouched here.
