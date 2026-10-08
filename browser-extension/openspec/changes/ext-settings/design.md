## Context

ext-accounts-and-unlock owns accounts, lock state and the timeout config object from ADR-002. ext-vault-browse owns sync, the cached type rows and copy. The popup shell has a Settings tab with no views. This change adds the schema everyone reads settings from, the views, PIN unlock and the two server-mirrored preference groups. The behaviours behind most Autofill and Notifications entries arrive with ext-autofill; this change only stores and shows the values.

Server facts used here, verified in the Keepiq app: `SettingsController::getUserSettings` and `updateUserSettings` behind `GET/PUT /api/settings/user`, whitelisted in `SettingsService::USER_PREF_KEYS`. Reads return every value as a string (`'1'`/`'0'` for toggles, `'login'` for `default_secret_type`); writes accept booleans or strings and echo the full preference set back. Secret type rows carry `name` (the slug the server stores) and `label`. The app registers no `ICapability`, so `/ocs/v2.php/cloud/capabilities` has no Keepiq entry.

## Goals / Non-Goals

**Goals:**
- One schema module that every change reads through, with a default and a validator per key.
- Bitwarden's Settings tab structure and defaults (ADR-001), with the deviations listed in the proposal.
- PIN unlock that keeps ADR-002's storage table honest: the only new place key material can live is a PIN-wrapped blob, and the user is told when it goes to disk.
- Server-mirrored preferences that never show a guess as fact: stale is visible, writes are online-only.
- A policy hook so an admin clamp can be wired in later without touching the views.

**Non-Goals:**
- Timeout enforcement, idle tracking and the lock itself (ext-accounts-and-unlock).
- Autofill, inline menu, context menu and the save prompts (ext-autofill).
- Folder CRUD (ext-vault-edit), in-extension import or export, biometrics, translations.
- Mirroring `session_timeout` into the extension's timeout (ADR-002 keeps them independent; it is displayed as "Web app default").

## Decisions

- **One schema module, scoped keys.** `src/settings/schema.ts` exports a `SETTINGS` table of `{ key, scope: 'account' | 'global' | 'server', default, validate }` and the derived `SettingsValues` type. Every read passes through `validate` and falls back to the default, so an old or hand-edited record can never produce an unexpected type. Alternative: per-module keys with local defaults, which is what produced Bitwarden's settings drift.
- **Two records, not one per key.** Account settings live in `storage.local` under `settings:<accountId>`, global (Appearance) under `settings:global`. One read per render, one write per change, and account removal deletes one key. Alternative: a key per setting, which needs a prefix scan to clear.
- **Appearance is global.** Theme must apply to the unlock and account-picker screens before an account is chosen, so it cannot be per account. Every other setting is per account because Bitwarden scopes them that way.
- **The background owns reads and writes; components go through hooks.** The `useSettings` hook sends `settings.get` and `settings.set`, applies `applyPolicy` to the returned values for display, and hands views the clamped values plus the policy for labels. The background validates, writes and returns the full snapshot, and applies the same `applyPolicy` for enforcement. No component touches `browser.storage` or the API client (ADR-004). `settings.set` takes a partial patch so one message serves every toggle.
- **Views compose shared components.** Every entry is a `SettingRow` (label, description, control slot) holding a `Toggle`, `Select` or link; the section list is a `SectionList`; the Never-timeout and PIN-on-disk confirmations are one `WarningDialog` with different copy; the PIN entry is a `PinDialog`. Alternative: markup per view, which is how the vanilla draft would have drifted.
- **Policy hook is a pure function.** `src/settings/policy.ts` exports `applyPolicy(values, policy)` where `policy` is the ADR-002 clamp object `{ maxTimeoutMinutes?: number; forcedAction?: 'lock' | 'logout' }`. Today the only source is the constant `NO_POLICY`. ADR-003's `GET /api/settings/policy` is a password policy and does not carry these fields, so no endpoint is wired; the hook is the single place to add one.
- **PIN wraps the PKCS#8 bytes, not a CryptoKey.** `src/settings/pin.ts` derives an AES-256-GCM key from the PIN with PBKDF2-SHA256, 600 000 iterations and a random 16-byte salt, and wraps the private-key bytes ADR-002 already keeps in `storage.session`. The blob uses the same `[version][salt][iv][ciphertext]` layout as the ADR-003 envelope so one codec serves both. Alternative: wrapping the master password, which would put a password-equivalent on disk.
- **Blob placement follows one toggle.** "Require master password on browser restart" on (default) puts the blob in `storage.session`; off puts it in `storage.local`. On Firefox below 115 the session variant falls back to a background-page variable, which the MV2 persistent page keeps until restart. The failed-attempt counter sits next to the blob in the same area so a browser restart cannot reset it when the blob survived.
- **The security trade-off, stated plainly.** A PIN is short. With the blob in `storage.local`, anyone who copies the profile can brute-force the PIN offline; PBKDF2 at 600 000 iterations costs about half a second per guess on a laptop, so a four-digit PIN falls in under two hours on one core and the five-attempt limit does not apply to an offline attacker. Mitigations: the default keeps the blob in `storage.session`, the minimum is four characters of any kind and the dialog recommends more, the on-disk variant shows a warning before it is stored, and the blob is purged on every lock path. Users who want the convenience accept the same risk Bitwarden's "Lock with master password on restart: off" carries.
- **Server mirror is a cache with a timestamp.** `src/settings/server-mirror.ts` performs `GET /api/settings/user` at the end of every sync and on Sync now, stores `{ values, fetchedAt }` under the `server` field of the account record, and marks it stale when the read fails or the extension is offline. Writes send the changed key as `'1'`/`'0'` or the type slug, wait for the echoed set and store it. No write queue: an offline toggle is disabled rather than deferred, because a queued write that lands hours later would surprise the user.
- **Default item type stores the slug.** The control lists cached type rows by `label`, stores `name` as `default_secret_type`, and ext-vault-edit resolves the slug to a `typeId` at create time. If the slug matches no cached row the control falls back to `login`.
- **Keyboard shortcut is read, not owned.** The background calls `browser.commands.getAll()`, which exists on both targets, and fills the snapshot's `shortcut` with the first command's binding or `null`; the `Autofill` view shows it or "Not set" when ext-autofill has not declared one. Chromium allows `browser.tabs.create({ url: 'chrome://extensions/shortcuts' })`; Firefox refuses privileged `about:` URLs from `tabs.create`, so Firefox gets instructions plus a Copy control. Detected with `import.meta.env.FIREFOX`.
- **Add current site uses activeTab.** Opening the popup from the toolbar action grants `activeTab` for that tab, so `browser.tabs.query({ active: true, currentWindow: true })` returns the URL without a `tabs` permission.
- **Theme via a root attribute from one hook.** `useTheme` runs in `<App />`, above the router, so it sets `data-theme` on the document root for every screen including unlock and the account picker, in a layout effect so the first paint is already themed; `popup.css` keeps `color-scheme: light dark` for System default and overrides the tokens under `[data-theme="light"]` and `[data-theme="dark"]`. Compact mode is a second attribute that tightens spacing tokens. Show animations toggles a `prefers-reduced-motion`-style class.
- **About reads the manifest.** `browser.runtime.getManifest().version` is identical on both targets. Store URLs are constants set to `<store-url>` until publication; the view hides Rate the extension while the constant is a placeholder.

## Module layout

New:
- `src/settings/schema.ts`: keys, scopes, defaults, validators, `SettingsValues`.
- `src/settings/store.ts`: read and write the two records, apply validation, delete on account removal.
- `src/settings/policy.ts`: `applyPolicy`, `NO_POLICY`, the clamp type.
- `src/settings/pin.ts`: derive, wrap, unwrap, blob codec, placement helper.
- `src/settings/server-mirror.ts`: fetch, write, stale computation for `/api/settings/user`.
- `entrypoints/popup/views/settings/SettingsIndex.tsx`: section list and navigation.
- `entrypoints/popup/views/settings/AccountSecurity.tsx`, `Autofill.tsx`, `Notifications.tsx`, `Vault.tsx`, `Appearance.tsx`, `About.tsx`: one view per section.
- `entrypoints/popup/components/SettingRow.tsx` (label, description, control slot), `Toggle.tsx`, `Select.tsx`, `SectionList.tsx`, `PinDialog.tsx`, `WarningDialog.tsx` (Never-timeout and PIN-on-disk confirmations).
- `entrypoints/popup/hooks/useSettings.ts` (over `settings.get` and `settings.set`, policy clamp applied), `useServerSettings.ts` (stale indicator, disabled offline, `settings.server.set`), `useTheme.ts` (global theme on the document root, including unlock and account screens).

Edited:
- `src/messages.ts`: the kinds below.
- `entrypoints/background.ts`: handlers for the new kinds, timeout config fed from the store through `applyPolicy`, PIN blob purge on lock, timeout, logout, account removal and suite change, server mirror refresh at the end of sync.
- ext-accounts-and-unlock's `Unlock` view component (`entrypoints/popup/views/Unlock.tsx`): a PIN field and "Use master password" link rendered when `useSettings` reports `pin.enabled`, sending `pin.unlock`.
- `entrypoints/popup/popup.css`: theme and compact tokens.

Nothing changes in `wxt.config.ts`. `storage` is already declared; `commands` belongs to ext-autofill.

## Message contract

```ts
// Popup → background, request/response.
export type PopupToBackground =
	| { kind: 'settings.get' }
	| { kind: 'settings.set'; patch: Partial<SettingsValues> }
	| { kind: 'settings.server.set'; patch: Partial<ServerSettings> }
	| { kind: 'pin.enable'; pin: string; requireMasterPasswordOnRestart: boolean }
	| { kind: 'pin.disable' }
	| { kind: 'pin.unlock'; pin: string }
	| { kind: 'about.get' }
	// existing kinds unchanged

export interface SettingsSnapshot {
	values: SettingsValues            // account and global keys merged and validated; useSettings applies the policy
	policy: TimeoutPolicy             // what applyPolicy clamped with, so views can label it
	server: { values: ServerSettings; fetchedAt: string | null; stale: boolean }
	pin: { enabled: boolean; onDisk: boolean }
	capabilities: { inlineMenu: boolean }   // reported by ext-autofill, false until it ships
	lastSyncAt: string | null
	shortcut: string | null
}

export type ServerSettings = {
	notify_shares: boolean
	notify_requests: boolean
	notify_group_shares: boolean
	notify_security: boolean
	default_secret_type: string
	session_timeout: 'session' | '10min' | '30min' | ''
}

export type TimeoutPolicy = { maxTimeoutMinutes?: number; forcedAction?: 'lock' | 'logout' }

export type PinUnlockResult =
	| { ok: true }
	| { ok: false; attemptsLeft: number }
	| { ok: false; attemptsLeft: 0; pinDisabled: true }

export interface AboutInfo {
	version: string
	serverOrigin: string | null
	storeUrl: string | null
}
```

`settings.set` and `settings.server.set` both reply with a fresh `SettingsSnapshot`. `settings.server.set` rejects with the server's `message` on a non-2xx reply and with `'offline'` when the request never reached the server.

## Risks / Trade-offs

- [PIN blob in `storage.local` is brute-forceable offline] → Default keeps it in `storage.session`; four-character minimum with a recommendation for more; warning before the on-disk variant is written; purge on every lock path.
- [MV3 worker restart mid-unlock loses an in-memory attempt counter] → The counter is stored beside the blob in the same storage area, never in a variable.
- [Firefox below 115 has no `storage.session`] → Session-scoped blob falls back to a background-page variable, which the persistent MV2 page keeps until restart; the same guard ADR-002 uses for the private key.
- [Firefox cannot open `about:addons` from `tabs.create`] → Instructions plus Copy control instead of a link on Firefox.
- [A server write lands while the cached copy is stale and overwrites a change made in the web app] → Writes send only the changed key and store the echoed full set, so the other keys are refreshed by the same request.
- [Policy clamp hides the user's stored timeout without explanation] → The view shows "Set by your organisation" and the clamped value; the stored value is not rewritten, so lifting the policy restores it.
- [Unknown keys from a newer extension version in an older one] → Validation drops unknown keys on read and preserves them on write by patching, not replacing, the record.
- [`default_secret_type` slug matches no cached type on a server with custom types removed] → Fall back to `login` with a visible note; never write the fallback back to the server unprompted.

## Open Questions

- Should Appearance settings become per account if a later change adds account-specific branding? Global for now.
- Does ext-autofill ship the inline menu in its first cut? The `capabilities.inlineMenu` flag keeps the toggle honest either way.
- Store URLs for Chrome Web Store and AMO are unknown until publication; constants stay `<store-url>`.
- Whether to show `session_timeout` at all under Account security as "Web app default", given ADR-002 says it does not drive the extension. Shown read-only for now.
