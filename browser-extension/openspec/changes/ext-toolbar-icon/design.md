## Context

By the time this change lands, the earlier changes provide:
- account status (Unlocked, Locked, Logged out) and the lock, unlock, logout and switch hooks (ext-accounts-and-unlock);
- the per-account snapshot in `storage.local` under `vault:<accountId>` (ext-vault-browse);
- the global Appearance record and the URI match default (ext-settings);
- `candidatesFor()` in `src/autofill/matcher.ts` and the `tabs` permission (ext-autofill).

`src/browser-action.ts` already resolves `action` against `browserAction`. The only icon art today is the coloured placeholder set in `public/icon/`.

## Goals / Non-Goals

**Goals:**
- One background module owns the icon, the badge and the tooltip; nothing else calls `setIcon`, `setBadgeText` or `setTitle`.
- The badge count equals the "Autofill suggestions" count by construction, not by keeping two copies of the rules in sync.

**Non-Goals:**
- Per-account icons or an avatar on the icon.
- Drawing the icon at runtime.
- An unlock prompt or other popup behaviour driven by the icon state.

## Decisions

- **Static PNG variants, not canvas.** There is one folder per state under `public/icon/`:
  - `toolbar/` (coloured)
  - `toolbar-locked/`
  - `toolbar-logged-out/`

  Each holds `16.png` and `32.png`. `setIcon({ path: { 16, 32 } })` works the same in the MV3 worker and the MV2 background page. Bitwarden ships static variants too. The alternative was greying and stamping a lock with `OffscreenCanvas` at runtime. It was rejected because it adds work on every worker wake and the art can't be reviewed as files. The manifest `icons` (48, 96, 128) stay the coloured set, since the extensions page and the store show those.
- **`action.default_icon` is the logged-out set.** The browser paints it before the background runs. A fresh install has no account, and after a restart every account is locked, so the startup listener swaps it to the lock almost at once. Starting coloured would briefly claim the vault is unlocked.
- **State icon is global, badge and count tooltip are per tab.** `setIcon` and the state title are set without `tabId`. `setBadgeText` and the count title are set with `tabId`, which Chrome resets on navigation. That reset is why the badge is recomputed on `tabs.onUpdated`.
- **Count through `candidatesFor()`.** `src/toolbar-icon.ts` calls ext-autofill's `candidatesFor(tab.url, snapshotItems, rule, lastUsed)` and takes `.length`. It passes an empty last-used map, because order doesn't matter for a count. The alternative was a separate counter. It was rejected because the badge would drift from the suggestions list the first time a rule changes.
- **One entry point: `refreshToolbar(scope)`.** `scope` is `'state'` (icon, global title, clear or recompute badges) or a tab id. Calls are coalesced per scope with a 100 ms timer, because `tabs.onUpdated` fires several times per load. Callers:
  - the ext-accounts-and-unlock hooks for unlock, lock, logout and switch, which trigger `'state'`;
  - `storage.onChanged` on `local` for `vault:<activeAccountId>`, `activeAccountId`, the active account's settings record and the Appearance record;
  - `tabs.onActivated`, `tabs.onUpdated` (when `url` or `status === 'complete'` changes), and `windows.onFocusChanged`;
  - `runtime.onStartup` and `runtime.onInstalled`.

  Lock state is not read from `storage.onChanged` on `session`, because on Firefox below 115 the key lives in memory and no event fires.
- **On `'state'` while unlocked, only each window's active tab is recomputed.** Other tabs pick up their count on activation. Recomputing every open tab on unlock costs a match pass per tab for badges nobody is looking at.
- **On lock or logout, every tab's badge is cleared.** This uses `setBadgeText({ text: '' })` globally plus one per-tab call for each tab from `tabs.query({})`, because a per-tab value overrides the global one.
- **Badge colours are set once at startup.** `setBadgeBackgroundColor` uses the Keepiq brand colour, and `setBadgeTextColor` uses white where the method exists. It exists on Chrome 110+ and Firefox 63+, and the call is guarded with `?.`. Both constants live in `src/toolbar-icon.ts`.
- **The tooltip name comes from `browser.runtime.getManifest().name`.** This keeps the dev build's " (DEV)" suffix without duplicating `wxt.config.ts`. "1 login for this site" uses the singular.
- **No new messages.** Everything happens inside the background. The setting is read and written through ext-settings' existing `settings.get` and `settings.set`, and the refresh is triggered by the storage change it causes.
- **Setting key `showBadgeCounter`** is added to the ext-settings schema with global scope and default `true`. The Appearance view renders it as a toggle in `entrypoints/popup/views/` alongside the existing entries, labelled as in the spec.

## Module layout

- `src/toolbar-icon.ts`: `refreshToolbar(scope)`, the coalescing timer, the icon paths per state, the badge text (`''`, `'1'`–`'9'`, `'9+'`), the tooltip strings, and `registerToolbarListeners()`.
- `src/browser-action.ts`: unchanged. `toolbar-icon.ts` imports `action` from it.
- `entrypoints/background.ts`: calls `registerToolbarListeners()` at top level, and calls `refreshToolbar('state')` from the existing lock-state hooks.
- `public/icon/toolbar*/16.png`, `32.png`: the art variants.
- `wxt.config.ts`: `action.default_icon` → `toolbar-logged-out`.
- The settings schema and Appearance view from ext-settings gain `showBadgeCounter`.

## Risks / Trade-offs

- [The badge reveals how many accounts the user has on the visible site to anyone who sees the screen] → This is Bitwarden's default. It is shown only while unlocked, and the setting turns it off.
- [The MV3 worker is asleep when a tab navigates] → `tabs.onUpdated` wakes it because the listener is registered at top level. The count comes from `storage.local`, so the cold path is one read.
- [A large vault makes each recompute a full match pass] → Recomputes are coalesced and limited to visible tabs. If profiling shows a cost, the snapshot can be indexed by base domain later without changing behaviour.
- [Placeholder art] → The variants are derived from the placeholder set and are replaced with it before release, per CLAUDE.md.
