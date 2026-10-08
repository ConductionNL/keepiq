## Context

The earlier chain members give the background an unlocked private key in `storage.session`, a ciphertext snapshot with plaintext `name`, `url`, `typeId`, `folderId` in `storage.local`, lazy decryption, the base-domain matcher `src/vault/match.ts`, the popup's "Autofill suggestions" section, the add form and the settings store (ADR-002). The content script is still the template scaffold. This change connects the vault to web pages the way Bitwarden's first-generation autofill does, and adds the save and update notification bar.

Constraints: content scripts run in hostile pages and get one credential per fill (ADR-002, config rule). Every API must exist on Chrome MV3 and Firefox MV2 or be shimmed (WXT-AND-BROWSERS.md). Keepiq has no per-item match type and no last-used field (ADR-003). The popup is React (ADR-004); background, content script, notification bar and offscreen document stay plain TypeScript.

## Goals / Non-Goals

**Goals:**
- Fill username and password from the popup, the context menu, a keyboard shortcut and, opt-in, on page load.
- Bitwarden's six match rules as a global default, public suffix aware.
- Save new logins and update changed passwords through a notification bar the page cannot tamper with.
- Copy from the background on both browsers with the clipboard clear delay.

**Non-Goals:**
- Inline menu on form fields, passkey provider, TOTP autofill after fill, card and identity fill (later changes).
- Narrowing content script `matches`; the fill and capture surfaces need to run on any login page, so `*://*/*` stays until the CLAUDE.md open decision is settled.
- Auto-submit after fill (Bitwarden does not).

## Decisions

- **Matching in the background, on `sender.url` and `tab.url`.** The content script never tells the background which item to use; it only reports whether it has fields. Alternative: matching in the content script, rejected because it needs vault metadata in page context.
- **`tldts` for the public suffix list.** Bitwarden uses it, it ships a compact list and handles `co.uk` and `github.io` correctly. Alternative: hand-maintained multi-label suffix set, rejected as a maintenance trap. If `src/vault/match.ts` from ext-vault-browse already uses `tldts`, `src/autofill/matcher.ts` wraps it; otherwise this change routes it through `tldts` too so the popup section and autofill agree.
- **Frame discovery by round trip, not `webNavigation`.** The background broadcasts `fill.collect` to the tab; every frame's content script replies `{hasUsername, hasPassword}` and the browser supplies `sender.frameId` and `sender.url`. The background then sends `fill.execute` only to the top frame and to frames whose `sender.url` matches the item, with `tabs.sendMessage(tabId, msg, { frameId })`. Alternative: `webNavigation.getAllFrames`, rejected to avoid another permission.
- **Insecure page confirm is a separate round trip.** For menu, shortcut and page-load fills the background sends `fill.confirmInsecure` to the top frame and waits for the boolean before decrypting. The popup asks in its own UI. Alternative: send the credential with a flag and let the content script confirm, rejected because plaintext would reach the page before consent.
- **Plaintext lifetime.** The background decrypts inside the fill handler, awaits the content replies, then lets the strings go out of scope; nothing is cached. The content script overwrites its local references after writing the fields. Matches ADR-002 "per fill".
- **Field filling dispatches events like Bitwarden.** Set the value through the native `HTMLInputElement.prototype.value` setter (so React's value tracker notices), then dispatch `keydown`, `keypress`, `keyup`, `input`, `change`. Alternative: `element.value = x` only, rejected because frameworks ignore it.
- **Context menu with `browser.contextMenus`.** Firefox exposes `contextMenus` as an alias of `menus` with the same permission name, so no shim. Menus persist across MV3 worker restarts, so the builder does `removeAll` then create, runs on `runtime.onInstalled`, `tabs.onActivated`, `tabs.onUpdated` (url change of the active tab), lock state change and vault sync. `contexts: ['editable']`. Fixed ids per entry, item entries `autofill:<itemId>`, `copy-user:<itemId>`, `copy-pass:<itemId>`.
- **Opening the popup from the background.** `action.openPopup()` where available (Chrome 127+, Firefox from a user-input handler), falling back to `browser.windows.create({ url: browser.runtime.getURL('/popup.html'), type: 'popup' })`, the Bitwarden popout. Lives in `src/browser-action.ts` next to the existing `action` shim.
- **Clipboard from the background.** Chrome MV3 has no DOM in the worker, so `browser.offscreen.createDocument({ url: 'offscreen.html', reasons: ['CLIPBOARD'], justification })` once, then `clipboard.write` messages; guarded by `if (browser.offscreen)`. Firefox MV2 background page calls `navigator.clipboard.writeText` directly under `clipboardWrite`. The clear timer reuses the copy-with-clear helper from ext-vault-browse and schedules through `browser.alarms` so an MV3 worker restart does not lose it.
- **Notification bar is an extension page in an iframe.** `entrypoints/notification/index.html` is listed in `web_accessible_resources`; the content script inserts an `<iframe>` pointing at `browser.runtime.getURL('/notification.html')` at the top of the page (position fixed, max z-index, inside a closed shadow root so page CSS cannot reach the host). The bar talks to the background itself; its `sender.url` is the extension origin and `sender.tab.id` identifies the capture. The content script only receives `capture.show` and `capture.hide`. Alternative: DOM bar in the content script, rejected because page scripts could read or alter it.
- **Capture queue keyed by tab id in background memory.** Bitwarden's notification queue shape: `{tabId, baseDomain, username, password, mode, itemId?, expiresAt}` with a five minute lifespan. Shown on `tabs.onUpdated` `status === 'complete'` when the tab's base domain still equals the capture's. On MV3 the queue dies with the worker; a lost prompt is acceptable, a persisted password is not.
- **Save shape.** `name` = page host, `url` = page origin, `typeId` = system `login` from the cached types, `login` and `key` encrypted with the cached certificate as in ext-vault-edit. Update re-fetches then `PUT` with `{ key }` only (ADR-002, ADR-003 sparse patch).
- **Last used in `storage.local`.** `lastUsed[accountId][itemId] = epochMs`, written after a successful fill, read for ordering and for the shortcut. Cleared with the account.
- **Content script `runAt: 'document_idle'` stays; capture listens with capture-phase `submit` and `click` on submit buttons plus `keydown` Enter in a password field, and `beforeunload` as a last chance.** Same as Bitwarden's collector. Reports once per submission using a per-form flag.
- **React only in the popup (ADR-004).** The popup additions are props and hooks on existing components: `ItemCard` and `Suggestions` from ext-vault-browse get an `onFill` callback, `ItemDetail` gets a Fill button, the insecure-page confirmation reuses `ConfirmDialog` from ext-vault-edit, and a `useFill` hook wraps `fill.request` so no component touches `browser.tabs` or `src/api` directly. The notification bar injected into pages is NOT React: it is a small vanilla DOM bundle (`entrypoints/notification/main.ts`) loaded in the isolated iframe, because React must not run in content scripts or their injected UI and the bar is a handful of elements. The offscreen document is plain TypeScript for the same reason.
- **Settings read through the ext-settings store**, keys as that change names them: URI match default, autofill on page load (off), ask to add login (on), ask to update existing login (on), excluded domains, clipboard clear delay, show context menu (on).

## Module layout

- `entrypoints/content.ts`: replace the scaffold. Hosts the message listener for `fill.collect`, `fill.confirmInsecure`, `fill.execute`, `capture.show`, `capture.hide`; wires the capture collector; sends `page_ready` and `capture.submitted`.
- `entrypoints/background.ts`: register the fill orchestrator, menu builder, `commands.onCommand`, capture queue and tab listeners; keep the single `onMessage` listener pattern with request/response and fire-and-forget arms.
- `entrypoints/offscreen/index.html`, `entrypoints/offscreen/main.ts`: Chrome clipboard document, plain TypeScript.
- `entrypoints/notification/index.html`, `entrypoints/notification/main.ts`, `entrypoints/notification/notification.css`: the bar UI, vanilla DOM, no React.
- `entrypoints/popup/hooks/useFill.ts`: `useFill()` returns `fill(itemId)` that resolves the active tab through the background (`fill.request` carries `tabId` from `tabs.query` in the background arm when omitted), handles `needsInsecureConfirm` by opening `ConfirmDialog`, shows the toast and calls `window.close()` on success.
- `entrypoints/popup/hooks/useActiveTab.ts`: `useActiveTab()` reads `{ url, fillable }` for the current tab through a `tabs.active` message so `Suggestions` can render "Autofill is not available on this page".
- `entrypoints/popup/components/ItemCard.tsx` (ext-vault-browse): add `onFill?: (itemId: string) => void`; when set, the card click fills and a Fill button is rendered.
- `entrypoints/popup/components/Suggestions.tsx` (ext-vault-browse): pass `onFill` from `useFill` to its cards.
- `entrypoints/popup/views/ItemDetail.tsx` (ext-vault-browse): Fill button shown when the item matches the active tab, calling `useFill`.
- `entrypoints/popup/components/ConfirmDialog.tsx` (ext-vault-edit): reused for the insecure-page warning; no new dialog component.
- `entrypoints/popup/components/Toast.tsx` (ext-vault-browse): "Unable to autofill on this page. Copy and paste instead."
- `src/autofill/matcher.ts`: `matches(itemUrl, pageUrl, rule)` for the six rules, on top of `src/vault/match.ts` and `tldts`; `candidatesFor(pageUrl, items, rule, lastUsed)`.
- `src/autofill/fields.ts`: `findLoginFields(root)` with visibility and shadow DOM handling; `fillField(el, value)`.
- `src/autofill/fill.ts`: background orchestrator (`collect`, insecure check, decrypt, dispatch, last-used write).
- `src/autofill/capture.ts`: content-side collector (`src/autofill/capture-collector.ts`) and background queue (`src/autofill/capture-queue.ts`).
- `src/autofill/notification-host.ts`: iframe host in a closed shadow root.
- `src/menus.ts`: builder and click handler.
- `src/last-used.ts`: `storage.local` read and write.
- `src/clipboard.ts` (from ext-vault-browse): add `writeFromBackground(text)` with the offscreen guard.
- `src/browser-action.ts`: add `openPopupOrPopout()`.
- `src/messages.ts`: envelopes below.
- `wxt.config.ts`: permissions `tabs`, `contextMenus`, `clipboardWrite`, `alarms`, plus `offscreen` when `browser === 'chrome'`; `commands.autofill_login` with `suggested_key` `Ctrl+Shift+L` and mac `Command+Shift+L`; `web_accessible_resources` for `notification.html` with `matches: ['*://*/*']` (WXT emits the MV2 string form for Firefox; verify in `.output/firefox-mv2/manifest.json`).

## Message contract

```ts
/** Popup, menu or command → background. Request/response. tabId omitted means the active tab. */
type FillRequest = { kind: 'fill.request'; itemId: string; tabId?: number; confirmedInsecure?: boolean }
type FillResult = { filled: 'both' | 'username' | 'password' | 'none'; needsInsecureConfirm?: boolean }

/** Popup → background. Request/response. Lets the popup render the not-available state without `tabs` access. */
type TabsActive = { kind: 'tabs.active' }   // reply: { url: string | null; fillable: boolean }

/** Background → every frame of a tab. Request/response. */
type FillCollect = { kind: 'fill.collect' }
type FillCollectReply = { hasUsername: boolean; hasPassword: boolean }
type FillConfirmInsecure = { kind: 'fill.confirmInsecure'; itemHost: string }   // reply: boolean

/** Background → one frame. Request/response. The only message carrying vault data. */
type FillExecute = { kind: 'fill.execute'; username: string | null; password: string | null }
type FillExecuteReply = { filled: 'both' | 'username' | 'password' | 'none' }

/** Content script → background. Fire-and-forget. URL comes from sender.url. */
type CaptureSubmitted = { kind: 'capture.submitted'; username: string | null; password: string; changed: boolean }

/** Background → content script. Fire-and-forget. */
type CaptureShow = { kind: 'capture.show' }
type CaptureHide = { kind: 'capture.hide' }

/** Notification page → background. Request/response. Tab comes from sender.tab. */
type CaptureState = { kind: 'capture.state' }   // reply: { mode: 'add' | 'update'; host: string; itemName?: string; folders: { id: string; name: string }[] }
type CaptureSave = { kind: 'capture.save'; folderId: string | null }
type CaptureUpdate = { kind: 'capture.update' }
type CaptureDismiss = { kind: 'capture.dismiss'; never: boolean }

/** Background → offscreen document (Chrome only). Request/response. */
type ClipboardWrite = { kind: 'clipboard.write'; text: string }
```

`ContentToBackground` gains `CaptureSubmitted`; `BackgroundToContent` gains `FillCollect`, `FillConfirmInsecure`, `FillExecute`, `CaptureShow`, `CaptureHide`; `PopupToBackground` gains `FillRequest` and `TabsActive`; new unions `NotificationToBackground` and `BackgroundToOffscreen`. Every listener keeps the `unknown` cast at the boundary.

## Risks / Trade-offs

- [Broad `*://*/*` content script triggers store review questions] → Proposal states why autofill needs it; the script is idle until a message or a submit, and the open decision stays tracked in CLAUDE.md.
- [Field heuristics miss unusual forms] → Partial fill plus the "Unable to autofill" toast and copy fallback; heuristics live in one module with fixtures so they can grow.
- [Capture queue lost on MV3 worker restart] → Accepted; the alternative is persisting a password, which ADR-002 forbids.
- [`action.openPopup` unavailable] → Popout window fallback.
- [`tldts` bundle size] → Import from `tldts` core only; still smaller than a stale hand-rolled list going wrong.
- [Page scripts spoofing `fill.*` or `capture.*` messages] → Background checks `sender.tab`, `sender.frameId`, `sender.url` origin on every arm; bar messages must come from the extension origin.
- [Clipboard clear races with the user's own copy] → Clear only if the clipboard still holds the copied value (Bitwarden behaviour).
- [Insecure confirm dialog can be spoofed by the page] → Only affects whether the user is asked; the page cannot obtain the credential without the affirmative reply reaching the background, and the popup path asks in extension UI.

## Open Questions

- Should the save bar offer "Edit" that opens the add form prefilled (Bitwarden's newer bar) in addition to direct Save? Deferred; direct Save with folder choice is implemented here.
- Should locked-vault submits show an "Unlock to save" bar (Bitwarden's newer behaviour) instead of being dropped?
- Exact settings key names depend on ext-settings; this design uses labels, tasks must use that change's identifiers.
