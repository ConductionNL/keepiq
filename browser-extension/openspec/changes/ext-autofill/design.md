## Context

The earlier chain members give the background an unlocked private key in `storage.session`, a ciphertext snapshot with plaintext `name`, `url`, `typeId`, `folderId` in `storage.local` (`login`, `key` and `additionalFields` are ciphertext), lazy decryption, the base-domain matcher `src/vault/match.ts` on `tldts`, the popup's "Autofill suggestions" section, the add form, the settings store with `inlineSuggestions` and `AUTOFILL_FEATURES`, the offscreen clipboard clear and `scheduleClipboardClear(ms)` in `src/clipboard.ts`, and `popout(tabId)` in `src/background/requests.ts` (ADR-002). The content script is still the template scaffold. This change connects the vault to web pages the way Bitwarden's autofill does, and adds the inline list and the save and update notification bar.

Constraints: content scripts run in hostile pages and get one credential per fill (ADR-002, config rule). Every API must exist on Chrome MV3 and Firefox MV2 or be shimmed (WXT-AND-BROWSERS.md). Keepiq has no per-item match type and no last-used field (ADR-003). The popup is React; pages injected into web pages are vanilla DOM (ADR-004).

## Goals / Non-Goals

**Goals:**
- Fill username and password from the popup, the inline list, the context menu, a keyboard shortcut and, opt-in, on page load.
- Bitwarden's six match rules as a global default, public suffix aware.
- An inline list beneath login fields that the page can neither read nor click on the user's behalf.
- Save new logins and update changed passwords through a notification bar the page cannot tamper with.
- Copy from the background on both browsers with the clipboard clear delay.

**Non-Goals:**
- Passkey provider, TOTP autofill after fill, card and identity fill, "New item" and password suggestions in the inline list (later changes).
- Narrowing content script `matches`; the fill and capture surfaces need to run on any login page, so `*://*/*` stays until the CLAUDE.md open decision is settled.
- Auto-submit after fill (Bitwarden does not).

## Decisions

- **Matching in the background, on `sender.url` and `tab.url`.** The content script never tells the background which item to use; it only reports whether it has fields, so no vault metadata reaches page context.
- **`src/autofill/matcher.ts` builds on `baseDomain()` in `src/vault/match.ts`,** which already uses `tldts` with private suffixes, so the popup section, the inline list and the context menu agree.
- **Frame registry from `page_ready`, not `webNavigation`.** Every frame's content script sends `page_ready`; the background records `{frameId, url}` per tab from `sender`, never from the message, and forgets a tab's frames when it starts loading a new page (`tabs.onUpdated` `status: 'loading'`). A fill sends `fill.execute` with `tabs.sendMessage(tabId, msg, { frameId })` to the top frame and to recorded frames whose URL matches the item. Without a record (the MV3 worker restarted), only the top frame gets it, after `tabs.get` shows the tab still matches. `tabs.sendMessage` without `frameId` resolves with one frame's reply only, so a broadcast cannot collect every frame.
- **Each frame checks its own origin before it fills.** `fill.execute` carries the origin the background matched; a content script whose `location.origin` differs (the frame navigated since it registered) fills nothing.
- **Insecure page confirm is a separate round trip.** For menu, shortcut and page-load fills the background sends `fill.confirmInsecure` to the top frame and waits for the boolean before decrypting. The popup and the inline list ask in their own UI. The credential is never sent with a flag for the content script to confirm, since plaintext would then reach the page before consent.
- **Plaintext lifetime.** The background decrypts inside the fill handler, awaits the content replies, then lets the strings go out of scope; nothing is cached. The content script overwrites its local references after writing the fields. Matches ADR-002 "per fill".
- **Field filling dispatches events like Bitwarden.** Set the value through the native `HTMLInputElement.prototype.value` setter (so React's value tracker notices), then dispatch `keydown`, `keypress`, `keyup`, `input`, `change`; frameworks ignore a plain `element.value = x`.
- **`autocomplete` is a signal, never a rule.** `classifyField()` in `src/autofill/fields.ts` weighs its token with type, name, id, placeholder, label, group shape and submit text, and ignores `autocomplete="off"` everywhere (spec `autofill`, Field detection, which also defines groups, formless ones included); fill, the inline list and capture all use it. Sites misuse `off` to block password managers and honouring it brings no security benefit, which is why browsers and Bitwarden ignore it for logins.
- **Classifier precedence:** hard exclusions (hidden, disabled, read-only) first, then group shape (number of password fields, a confirm field), then input type, then `autocomplete` token, name, id, label and submit text as tie-breakers.
- **Keywords cover English and Dutch at least,** in submit text and labels:
  - Login: "Log in", "Sign in", "Inloggen"; next: "Next", "Continue", "Volgende", "Doorgaan".
  - Sign-up: "Register", "Sign up", "Create account", "Registreren", "Account aanmaken".
  - Subscribe (never a login): "Subscribe", "Inschrijven", "Aanmelden voor nieuwsbrief".
  - "Aanmelden" alone is ambiguous in Dutch (log in or sign up), so it counts only with other evidence, such as a password field or a "nieuwsbrief" label.
- **Context menu with `browser.contextMenus`.** Firefox exposes `contextMenus` as an alias of `menus` with the same permission name, so no shim. Menus persist across MV3 worker restarts, so the builder does `removeAll` then create, runs on `runtime.onInstalled`, `tabs.onActivated`, `tabs.onUpdated` (url change of the active tab), lock state change and vault sync. `contexts: ['editable']`. Fixed ids per entry, item entries `autofill:<itemId>`, `copy-user:<itemId>`, `copy-pass:<itemId>`.
- **Opening the popup from the background.** `openPopupOrPopout(tabId)` in `src/browser-action.ts`: `action.openPopup()` where it works (Chrome 127+, Firefox 149+; older Firefox only from a user-input handler), else `popout(tabId)` from `src/background/requests.ts`, exported for this, the Bitwarden popout.
- **Clipboard from the background.** Generalise `overwriteClipboard()` in `src/clipboard.ts` to `writeClipboard(text)`, and add `writeFromBackground(text, clearMs)` beside `clearClipboard()`: on Chrome it creates the existing offscreen document, sends `offscreen.writeClipboard` and closes it, as the clear does; on Firefox the background page writes itself. On success it calls `scheduleClipboardClear(clearMs)`, so the clear survives an MV3 worker restart through `browser.alarms`; the caller passes `clearClipboardMs`, so `clipboard.ts` stays free of the settings code. The offscreen page answers only the worker (`sender.url` is `background.js`).
- **Notification bar is an extension page in an iframe.** `entrypoints/notification/index.html` is listed in `web_accessible_resources`; the content script inserts an `<iframe>` pointing at `browser.runtime.getURL('/notification.html')` inside a closed shadow root, on `frame-host.ts` like the inline list. The bar talks to the background itself; its `sender.url` is the extension origin and `sender.tab.id` identifies the capture. The content script only receives `capture.show`, `capture.hide`, `capture.verify`, `capture.focus` and `capture.expand`, sent with `{ frameId: 0 }` so only the top frame hosts the bar. A bar built in the content script's DOM would be readable and alterable by page scripts. See § Notification bar.
- **Capture queue keyed by tab id in background memory.** Bitwarden's notification queue shape: `{tabId, baseDomain, tabBaseDomain, username, password, mode, itemId?, expiresAt}` with a five minute lifespan. `baseDomain` is the submitting frame's and decides add or update, the excluded check and the saved `name` and `url`; `tabBaseDomain` is the top frame's at submit. Shown 300 ms after the submit unless the tab starts loading by then, else on `tabs.onUpdated` `status === 'complete'`, and on the top frame's `page_ready`, while the tab's base domain still equals `tabBaseDomain`. On MV3 the queue dies with the worker; a lost prompt is acceptable, a persisted password is not.
- **Save shape.** `name` = page host, `url` = page origin, `typeId` = system `login` from the cached types, `login` and `key` encrypted with the cached certificate as in ext-vault-edit. Update re-fetches with `GET /api/v1/secrets/{id}`, then saves a `{ key }` patch through `updateItem` and share-save.
- **Last used in `storage.local`.** `lastUsed.<accountId>` holds `{ [itemId]: epochMs }`, written after a successful fill while the account is unlocked, dropping ids the cached vault no longer holds; read for ordering and for the shortcut. `forgetLastUsed()` clears it on logout and account removal, on the same per-account queue as the writes.
- **Content script `runAt: 'document_idle'` stays; capture listens with capture-phase `submit` and `click` on each group's own submit button (a `<form>`'s default submit button when its text is a keyword, otherwise the closest keyword button after its password field, so a header "Sign up" is not one) plus `keydown` Enter in a password field, and `beforeunload` as a last chance.** Same as Bitwarden's collector. Reports once per submission using a per-group flag, only for trusted events with transient user activation, and at most once per activation: whether a bar follows tells the page whether the username is saved, so a script's submit must not be a probe.
- **React only in the popup (ADR-004).** The popup additions are props and hooks on existing components: `ItemCard` and `Suggestions` from ext-vault-browse get an `onFill` callback, `ItemDetail` gets a Fill button, the insecure-page confirmation reuses `ConfirmDialog` from ext-vault-edit, and a `useFill` hook wraps `fill.request` so no component touches `src/api` directly. The notification bar and the inline list are vanilla DOM bundles loaded in isolated iframes (ADR-004). The offscreen document stays plain TypeScript.
- **Each extension page gets only its own message kinds.** `senderPage()` and `acceptedFrom()` in `src/background/sender.ts` route by `sender.url` path, cheap hardening so a bug in one page cannot reach another page's kinds. Popup kinds (`fill.request` included) only from `/popup.html`, `capture.*` decisions only from `/notification.html`, list kinds only from `/inline-list.html`; content scripts may send only `page_ready`, `inline.open`, `inline.closed`, `capture.submitted` and `capture.reshow`.
- **Settings read through `readSettings(accountId)`** of the active account: `uriMatch`, `autofillOnPageLoad` (off), `defaultAutofill` (`onPageLoad`), `inlineSuggestions` (on), `contextMenu` (on), `askToAdd` (on), `askToUpdate` (on), `excludedDomains`, `clearClipboardMs`.

## Inline list

Bitwarden's inline autofill menu, reduced to the login list. Matching and decryption stay in the background; the content script knows only that a list is open.

### Opening

- `isQualifying()` in `src/autofill/fields.ts` decides from the DOM on `classifyField()`: a username or current-password field of a group classified as a login qualifies.
- Listeners are capture-phase `focusin`, `pointerdown`, `click`, `input` and `keydown` on `document`.
- Any focus of an empty qualifying field opens the list, script focus and `autofocus` included; so does a trusted click in a qualifying field.
- At start the content script checks `document.activeElement`, through open shadow roots, for a field focused before `document_idle`.
- The content script sends `inline.open`; the background answers `{ key }`, or `null` without a logged-in account, with `inlineSuggestions` off, or for a non-`http(s)` `sender.url`.
- The height is five rows, or less in a small frame (§ Position), and the same in every state, so the content script sizes and positions the host without waiting for the list.

### Session

- One per tab, `inlineSession.<tabId>` in `storage.session` (background memory on Firefox below 115), so a worker restart does not break a pick.
- Holds `{ key, accountId, frameId, frameUrl, listFrameId, itemIds, openedAt }`: `key` 128 random bits, frame and URL from `sender`, no plaintext.
- `listFrameId` is bound by the first accepted `inline.state`.
- Dropped on `inline.closed`, the next `inline.open` in the tab, tab close, the tab starting to load, lock, logout and account switch.
- Two opens racing from different frames close the first list instead of orphaning it.

### Host

- `src/autofill/inline-host.ts` appends one element, a closed shadow root holding `<iframe src="inline-list.html" title="Keepiq logins">`, to `document.documentElement`.
- When the field is inside a modal dialog (`:modal`, found across shadow roots) the host goes inside that dialog instead, since everything outside it is inert, popovers included.
- Where the host is a popover, the nearest `[aria-modal="true"]` ancestor counts the same way, since its script focus trap (Radix, MUI, Headless UI, react-focus-lock) pulls focus out of a host outside it. Without the popover API the host stays on `<html>`, out of reach of the modal's styling. A faked `aria-modal` can only cause refusals or a close.
- The host's `style` attribute is `all: initial !important` followed by each needed property with `!important` (`overflow: clip`), so page rules such as `:popover-open` or `html > :last-child` cannot restyle it.
- The iframe has `color-scheme: light dark`, so the list's "System" theme follows the OS.
- Where `showPopover` exists the host is `popover="manual"` in the top layer, out of reach of page stacking and `<html>` effects; elsewhere (Firefox below 125) it is fixed with max `z-index`. Either way it is placed with `top` and `left`, never a `transform`, which the visibility guard refuses.
- A `MutationObserver` on the host's attributes and its parent's children closes the list on any change to the host, or its removal, that the content script did not make; it drops its own records with `takeRecords()` after each write.
- A `beforetoggle` or `toggle` event the content script did not cause (the page calling `hidePopover()` or `showPopover()`) closes the list; so does a sample finding the host no longer `:popover-open`, for a page that stopped those events first.
- The background sends `inline.ready` to the host right after the key and sender checks of `inline.state`, before any vault work.
- Without `inline.ready` within 3 seconds of the iframe's `load`, the host removes itself (a page CSP blocking the frame).

### Key hand-off

- After the iframe's `load`, the content script posts `inline.init` with the key to `iframe.contentWindow`, `targetOrigin` the extension origin, so a frame the page navigated elsewhere never gets it.
- A second `load` of the iframe (the page navigated it) closes the list.
- The page can post a false `inline.init`, so the list page tries each until the background accepts one, then takes only `inline.focus` and `inline.moved` with that key.
- The list page reads nothing from its URL and registers no `runtime.onMessage` listener.
- The background accepts list messages only from `/inline-list.html` in the session's tab, from `listFrameId`, with the key; a copy the page frames itself shows nothing.

### Contents

- `inline.state` replies `locked`, or `unlocked` with `rows` from `candidatesFor(frameUrl, …)`: `{ id, name, username }`, `username` the decrypted `login` or `null`.
- Use-only copies are listed: they exist to be filled (ADR-003, `useOnly`).
- The reply also carries the host and the theme; the rows live in the list page until the iframe goes.
- Rows beyond what the height shows scroll inside the list.
- A click on the no-match or locked message does nothing: closing on it would tell the page, by the close, that no login matched.

### Position

- Below the field, left edges aligned, at least the field's width and 240 CSS px, at most the frame's viewport width minus 16 px; when less than 240 px fits, the list does not open.
- Height: five rows, or the larger free side of the field within the frame's viewport when that is smaller, fixed when the list opens; when fewer than two rows fit, the list does not open.
- On the side with more room when there is less room below than the list's height; never over the field. When neither side fits it any more, the list closes.
- Repositioned in each `requestAnimationFrame` sample while open, which follows scrolling, `resize`, `visualViewport` changes and layout shifts alike.
- Each change of position posts `inline.moved` with the key to the list page.
- Closed when the field is removed, hidden or fully out of the viewport.

### Keyboard

- Opening never moves focus.
- ArrowDown in the field is relayed only after the field has held focus for 500 ms, or after a trusted `pointerdown` on it or a Tab into it the page did not cancel (a page moving focus itself in the user's Tab cancels it); otherwise it reaches the page. A held-down repeat is never relayed.
- A relayed ArrowDown focuses the iframe and posts `inline.focus` in every state; the list page focuses the first row, Unlock when locked, or the no-match message (`tabindex="-1"`). The content script never needs the state.
- Escape in the field closes the list. Both keys are consumed only while the list is open.
- Tab or Shift+Tab in the field closes the list before focus moves, so it never lands in the list, which comes after the page's controls in tab order.
- With rows the list page is a `role="listbox"` with roving focus over `role="option"`: Up/Down, Home/End, Enter or Space picks.
- Up on the first row, on Unlock or on the no-match message sends `inline.refocus`; the background forwards it and the content script focuses the field, the list staying open.
- Escape, Tab and Shift+Tab send `inline.close { refocus: true }`.
- On window `blur` the list page blurs its active element, so a window `focus` the page causes with `frames[n].focus()` lands on no row.
- The insecure question's title is "Fill on this insecure site?", then the warning; it opens with focus on Cancel, and Escape cancels and returns focus to the row.
- The `role="status"` region is in the page from the start, empty, and is filled 250 ms after the state arrives, so screen readers announce it: "<n> Keepiq logins. Press Down Arrow to choose.", "No Keepiq logins for <host>" or "Keepiq is locked. Press Down Arrow to unlock."

### Pick

- `inline.pick` carries the key, `itemId`, `confirmedInsecure?` and `input`: the `pointerdown` time and point, or the keydown time and the focused row's centre.
- The background checks the session, that `itemId` was offered and still matches `frameUrl`, and that the vault is unlocked.
- It sends `inline.verify` with `input` to the host frame; on `false` it decrypts nothing and answers `refused: 'not_visible'`, leaving the list open to explain (§ Close and unlock).
- An `https` item on an `http` frame gets `needsInsecureConfirm`, and the list asks in its own UI.
- It counts the pick as interaction, decrypts `login` and `key`, sends one `fill.execute` with `anchorKey` to `frameId` and records last used. A refused pick is no interaction.
- The content script fills only the form of the field the list was opened for, and only while its open list has that key.
- Then, and after `locked` or `stale`, the background sends `inline.close { refocus: true }`; the content script suppresses the reopen its own refocus would cause.

### Visibility guard (DOM clickjacking)

- The list page ignores pointer, Enter and Space picks within 500 ms of being shown, of `inline.moved`, or of its content changing (the insecure question).
- While open, the content script samples visibility every animation frame and keeps `lastNotVisibleAt`.
- Visible: host on its parent and untampered, still `:popover-open` where it is a popover, its measured rect within 0.5 px of the one the content script set, no generated `::before` or `::after` content on it, and the host itself "untouched": opacity 1, `zoom` 1 and no `filter`, `mix-blend-mode`, `transform`, `scale`, `rotate`, `translate`, `visibility: hidden`, `clip-path`, `mask`, `mask-image`, `mask-border-source` or `-webkit-mask-box-image`.
- A showing popover paints and takes clicks whatever its ancestors' opacity, filter, transform, mask or visibility (verified in Chromium 151), so with the popover API the ancestors in the host's own document are not checked; what can still hide it shows elsewhere: `display: none`, `content-visibility: hidden` and an inherited `zoom` in the measured rect, `inert` and overlays in the hit test. This also keeps dialogs centred with `transform: translate(-50%, -50%)` pickable.
- Firefox's exception: a `filter` on `<html>` reaches its top layer (`opacity(0)` hides the list, `invert(1)` inverts it, and clicks still land; verified in Firefox 153), so on Firefox the popover path also refuses while `<html>` has a `filter`. Its top layer ignores everything else on `<html>` and on other ancestors.
- Without the popover API (Firefox below 125) the host is under its ancestors' styling, so every ancestor across shadow roots up to `<html>` must be untouched too.
- In a subframe the sample also checks, for each ancestor frame, its `frameElement` and every ancestor element of it across shadow roots up to that document's root, the same way, except that a plain translation (`matrix(1, 0, 0, 1, x, y)`, or a two-value `translate`) is accepted there: the press point is mapped through `getBoundingClientRect`, which follows it. Scale, rotation, skew and depth stay refused.
- `inline.verify` answers `false` when `lastNotVisibleAt` falls in the 500 ms before `input` or any time after it, or when the host is not what is hit at the input point; in a subframe also when, in any ancestor document at the point mapped into it, that frame's `frameElement` is not. Hit testing goes through each shadow root above the element, then its document.
- Each show and move of the host counts as a moment it was not visible, so the content script enforces the list page's 500 ms window too.
- On Chrome the list page and the content script also keep the last time an `IntersectionObserver` with `trackVisibility` (delay 100 ms) reported the list not visible, and refuse on the same window. It does not report masks, so on Chrome the guarantee is IntersectionObserver v2 plus the same-origin ancestor checks; masks are covered by refusing cross-origin ancestors (§ Frames).
- On Firefox the guard is best effort: it misses `pointer-events: none` overlays, and styles changed only between samples, since a page `requestAnimationFrame` callback registered after ours can hide the list for a painted frame and restore it before our next sample.

### Close and unlock

- The list closes on Escape, Tab in the field, focus leaving field and list, typing in the field, `visibilitychange` to hidden, the field leaving the viewport, and `inline.close` from the background (pick, lock, logout, account switch, Escape or Tab in the list).
- The content script then removes the host and sends `inline.closed`. When focus was inside the list, it returns focus to the field whatever `refocus` says, so focus is never lost with the frame.
- A pick refused as `not_visible`, or blocked by the list page's own `trackVisibility` check on Chrome, leaves the list open, showing and focusing "Keepiq can't fill here because this page changes how it is shown. Use the Keepiq button in the toolbar." (not also through the status region); Escape or Tab then closes it with refocus. Assistive styling refuses every pick where it changes what the guard checks: a zoom extension's `zoom` on `<html>` always; a `filter` on `<html>` (Dark Reader's filter mode, "Invert the page") on Firefox; and a high-contrast extension's `transform` or opacity on `<html>` only without the popover API.
- Unlock calls `action.openPopup()` in its click handler before any `await`, which Firefox below 149 requires; when the popup opens, the list page sends `inline.close { refocus: false }`.
- When that fails it sends `inline.unlock`; the background opens `popout(tabId)` and closes the list. Picks are not queued across unlock.

### Frames

- The content script runs with `allFrames: true`; a list matches its own frame's `sender.url`, and a pick fills only that frame.
- Both browsers: only the top frame and subframes whose ancestor frames are all same-origin with them, found by walking `window.frameElement` to the top and comparing origins (an opaque origin never matches), so the sample can read every ancestor.
- Chrome too, since a cross-origin ancestor can hide the list with a `mask-image` while clicks still reach it, which `trackVisibility` does not report (verified in Chromium 151).

### Manifest and processes

- `web_accessible_resources` lists `inline-list.html` for `*://*/*`; its scripts load from the extension origin and need no entry.
- WXT emits MV2's string form for Firefox, whose per-install UUID keeps the URL unguessable. Chrome's URL is public, and a copy the page frames itself gets no key (spec scenario "Page frames the list page itself").
- The list's iframe runs outside the page's process (Chrome, and Firefox with its default out-of-process extensions), so names and usernames never enter the page's renderer.

## Notification bar

The save and update bar reuses the inline list's host and guard; matching, decryption and the password stay in the background.

- **Key.** Each show issues a random 128-bit key with `capture.show`; the host posts it as `capture.init` to its own frame at the extension origin after the frame's `load`, and a second `load` closes the bar. The background binds the first frame presenting a key in `capture.state` and answers nothing else; up to four recent keys stay valid, for a bar a slower page load still shows.
- **Host.** A `popover="manual"` in the top layer where the popover API exists, inside the modal dialog or `aria-modal` container around focus; else fixed at max `z-index` on `<html>`. Full width at the top, `top`/`left` only, the style attribute written whole as in § Host.
- **Guard.** As § Visibility guard, through `src/autofill/visibility.ts`: a `MutationObserver` and toggle events close the bar on a change the host did not make; every animation frame samples it with `drawnUntouched()`; `capture.verify` answers whether the bar was visible for the 500 ms before the press and is what the press point hits. Save, Update and Never go through it; Close does not.
- **Height.** By the top frame's viewport alone (`barHostHeight` in `src/messages.ts`), so neither the mode nor the text shows in the page's layout: 64, 96 or 128 px by width, and `BAR_COMPACT_HEIGHT_PX` (40) where that would cover more than a third of the viewport height.
- **Expand.** A compact bar grows to its full height while focus is in it: on a trusted `focusin` of the host, or the bar's `capture.expand` relayed by the background; it shrinks on the next `focusin` outside or animation frame without focus, since focus leaving for nothing focusable sends the host no event. Every resize posts `capture.moved`.
- **Shortcut.** `focus_notification_bar` suggests Alt+Shift+K (macOS Command+Shift+K): every Ctrl+Shift letter is taken by Chrome, Firefox, IBus or Bitwarden, Chrome's Alt+Shift uses only A, I, N and T, and neither browser binds Command+Shift+K; `capture.state` names the assigned key for the bar to announce.
- **Focus.** The host never takes focus. The command → `capture.focus` → the frame is focused and gets `capture.focus`. A hide while focus is in the bar returns it to the page element that last had it; the hide after a save waits for focus and pointer to leave. Each animation frame and `focusin` check the page's focused element: under the bar, the host moves to the other edge, unless the element is under that too; it does not move back until focus is on an element under it again.
- **Modals.** Each animation frame looks for a `:modal` or `aria-modal` ancestor of focus that the host is not in, and for the host's modal having closed; the host moves with `moveBefore`, which keeps the frame's document, disconnecting its observer around the move. Without `moveBefore` the host closes and sends `capture.reshow`, and the background shows a new bar with a new key.

## Module layout

- `entrypoints/content.ts`: replace the scaffold; `allFrames: true`. Hosts the message listener for `fill.confirmInsecure`, `fill.execute`, `inline.ready`, `inline.verify`, `inline.refocus`, `inline.close`, `capture.show`, `capture.hide`, `capture.verify`, `capture.focus`, `capture.expand`; wires the capture collector and the inline list triggers; sends `page_ready`, `inline.open`, `inline.closed`, `capture.submitted` and `capture.reshow`.
- `entrypoints/background.ts`: register the fill orchestrator, frame registry, inline sessions, menu builder, `commands.onCommand`, capture queue and tab listeners; keep the single `onMessage` listener pattern with request/response and fire-and-forget arms, routed by sender page.
- `entrypoints/offscreen/main.ts` (exists): also answers `offscreen.writeClipboard`.
- `entrypoints/notification/index.html`, `entrypoints/notification/main.ts`, `entrypoints/notification/bar.ts`, `entrypoints/notification/notification.css`: the bar UI, vanilla DOM, no React.
- `entrypoints/inline-list/index.html`, `entrypoints/inline-list/main.ts`, `entrypoints/inline-list/list.ts`, `entrypoints/inline-list/inline-list.css`: the list UI, vanilla DOM, no React; text through `i18n.t()`, `<html lang>` from the catalog.
- `entrypoints/popup/hooks/useFill.tsx`: `useFill(tab)` takes the tab the popup already read with `useCurrentTab()`, so suggestions and fill target the same tab, and returns `{ fill, dialog }`: `fill(itemId)` sends `fill.request` with it, handles `needsInsecureConfirm` by opening `ConfirmDialog` as `dialog`, shows the toast and calls `window.close()` on success outside a popout. `Suggestions` gets `host` as a prop and renders "Autofill is not available on this page" when it is `null`.
- `entrypoints/popup/components/ItemCard.tsx` (ext-vault-browse): add `onFill?: (itemId: string) => void`; when set, the card click fills and a Fill button is rendered.
- `entrypoints/popup/components/Suggestions.tsx` (ext-vault-browse): pass `onFill` from `useFill` to its cards.
- `entrypoints/popup/views/ItemDetail.tsx` (ext-vault-browse): Fill button shown when the item matches the active tab, calling `useFill`.
- `entrypoints/popup/components/ConfirmDialog.tsx` (ext-vault-edit): reused for the insecure-page warning; no new dialog component.
- `entrypoints/popup/components/Toast.tsx` (ext-vault-browse): "Unable to autofill on this page. Copy and paste instead."
- `src/autofill/matcher.ts`: `matches(itemUrl, pageUrl, rule)` for the six rules, on top of `src/vault/match.ts` and `tldts`; `candidatesFor(pageUrl, { secrets, types }, rule, lastUsed)`, the types naming which `typeId` is `login`.
- `src/autofill/fields.ts`: `findLoginFields(root)` with visibility and shadow DOM handling, and whether a field qualifies for the inline list; `fillField(el, value)`.
- `src/autofill/frames.ts`: the frame registry from `page_ready`.
- `src/autofill/fill.ts`: background orchestrator (frame selection, insecure check, decrypt, dispatch, last-used write); the popup, menu, shortcut, page-load and inline paths all go through it.
- `src/autofill/triggers.ts` (background): fill on page load and from the `autofill_login` shortcut.
- `src/autofill/execute.ts` (content): `executeFill()` answering `fill.execute`.
- `src/autofill/capture-collector.ts` (content) and `src/autofill/capture-queue.ts` (background).
- `src/autofill/frame-host.ts`: the closed-shadow-root iframe host shared by the bar and the list.
- `src/autofill/inline-host.ts` (content): triggers, positioning, keyboard relay, tamper and visibility checks, on `frame-host.ts`.
- `src/autofill/inline-session.ts` (background): `inline.*` handlers and the session store.
- `src/autofill/notification-host.ts`: the bar's placement, key hand-off, guard and focus on `frame-host.ts`.
- `src/autofill/visibility.ts`: the guard's checks, shared by `inline-host.ts` and `notification-host.ts`.
- `src/autofill/visibility-tracker.ts`: `trackVisibility()`, the IntersectionObserver v2 check shared by `entrypoints/inline-list/list.ts` and `entrypoints/notification/bar.ts`.
- `src/background/sender.ts`: `senderPage()` and `acceptedFrom()`, the kinds each sender page may send.
- `src/autofill/tab-items.ts` (background): the item each tab last filled or captured, for password change forms.
- `src/menus.ts`: builder and click handler.
- `src/last-used.ts`: `storage.local` read and write.
- `src/clipboard.ts` (exists): `writeClipboard(text)` from `overwriteClipboard()`, and `writeFromBackground(text, clearMs)`.
- `src/browser-action.ts`: add `openPopupOrPopout(tabId)`.
- `src/messages.ts`: envelopes below.
- `wxt.config.ts`: add permission `contextMenus` (`tabs`, `alarms`, `clipboardWrite` and Chrome's `offscreen` are declared already); `commands.autofill_login` with `suggested_key` `Ctrl+Shift+L` and mac `Command+Shift+L`, and `commands.focus_notification_bar` with `Alt+Shift+K` and mac `Command+Shift+K` (§ Notification bar); `web_accessible_resources` for `notification.html` and `inline-list.html` with `matches: ['*://*/*']` (WXT emits the MV2 string form for Firefox; verify in `.output/firefox-mv2/manifest.json`).

## Message contract

```ts
/** Popup, menu or command → background. Request/response. tabId omitted means the active tab. */
type FillRequest = { kind: 'fill.request'; itemId: string; tabId?: number; confirmedInsecure?: boolean }
type FillResult = { filled: 'both' | 'username' | 'password' | 'none'; needsInsecureConfirm?: boolean }

/** Background → the top frame. Request/response. */
type FillConfirmInsecure = { kind: 'fill.confirmInsecure'; itemHost: string }   // reply: boolean

/**
 * Background → one frame. Request/response. The only message to a content script carrying vault data.
 * `origin`: the frame origin the background matched. `anchorKey`: an inline pick; fill only the form the open list with this key belongs to.
 */
type FillExecute = { kind: 'fill.execute'; username: string | null; password: string | null; origin: string; anchorKey?: string }
type FillExecuteReply = { filled: 'both' | 'username' | 'password' | 'none' }

/** Content script → background. Frame and URL come from sender. */
type InlineOpen = { kind: 'inline.open' }   // request/response, reply: { key: string } | null
type InlineClosed = { kind: 'inline.closed'; key: string }   // fire-and-forget; the key, so a late close never drops a newer session

/** Content script → its own list iframe, by postMessage to the extension origin. */
type InlineInit = { kind: 'inline.init'; key: string }
type InlineFocus = { kind: 'inline.focus'; key: string }
type InlineMoved = { kind: 'inline.moved'; key: string }

/** List page → background. Request/response unless noted. Tab and frame come from sender. */
type InlineState = { kind: 'inline.state'; key: string }
type InlineRow = { id: string; name: string; username: string | null }
type InlineStateReply = { state: 'locked' | 'unlocked'; host: string; rows: InlineRow[]; theme: Theme } | null
/** `at`: `Date.now()` of the pointerdown or keydown. `x`, `y`: that point, or the focused row's centre, in list coordinates. */
type PickInput = { kind: 'pointer' | 'key'; at: number; x: number; y: number }
type InlinePick = { kind: 'inline.pick'; key: string; itemId: string; input: PickInput; confirmedInsecure?: boolean }
/** The list stays open after `needsInsecureConfirm` and `not_visible`; the background closes it after the others. `null`: wrong key, tab or frame. */
type InlinePickReply = { filled: 'both' | 'username' | 'password' | 'none' } | { needsInsecureConfirm: true } | { refused: 'locked' | 'not_visible' | 'stale' } | null
type InlineRefocus = { kind: 'inline.refocus'; key: string }   // fire-and-forget
type InlineClose = { kind: 'inline.close'; key: string; refocus: boolean }   // fire-and-forget
type InlineUnlock = { kind: 'inline.unlock'; key: string }   // fire-and-forget

/** Background → the frame hosting the list. `key`: the session's, so a message for a list since closed cannot reach the next. */
type InlineReady = { kind: 'inline.ready'; key: string }   // fire-and-forget
type InlineVerify = { kind: 'inline.verify'; key: string; input: PickInput }   // request/response, reply: boolean
type InlineRefocusHost = { kind: 'inline.refocus'; key: string }   // fire-and-forget
type InlineCloseHost = { kind: 'inline.close'; key: string; refocus: boolean }   // fire-and-forget

/** Shared by host and list page: the iframe is rows × 44 px tall, 2 to 5 rows, at least 240 px wide. */
const INLINE_ROW_PX = 44, INLINE_MAX_ROWS = 5, INLINE_MIN_ROWS = 2, INLINE_MIN_WIDTH_PX = 240

/** Content script → background. Fire-and-forget. URL comes from sender.url. */
type CaptureSubmitted = { kind: 'capture.submitted'; username: string | null; password: string; changed: boolean }

/** Background → the top frame (`frameId: 0`). `done`: after a save or update, so the host waits for focus and pointer to leave. */
type CaptureShow = { kind: 'capture.show'; key: string }
type CaptureHide = { kind: 'capture.hide'; key: string; done?: true }
type CaptureVerify = { kind: 'capture.verify'; key: string; input: PickInput }   // request/response, reply: boolean
type CaptureFocus = { kind: 'capture.focus' }   // the focus_notification_bar command
type CaptureExpandHost = { kind: 'capture.expand'; key: string }   // relayed from the bar

/** Content script → background, top frame only, fire-and-forget: closed to reopen inside a modal, without `moveBefore`. */
type CaptureReshow = { kind: 'capture.reshow' }

/** Content script → its own bar iframe, by postMessage to the extension origin. */
type ContentToNotification = { kind: 'capture.init' | 'capture.focus' | 'capture.moved'; key: string }

/** Notification page → background. Request/response. Tab comes from sender.tab; null for no capture or an unbound key or frame. */
type CaptureState = { kind: 'capture.state'; key: string }   // reply: CaptureStateReply | null; binds the frame
type CaptureStateReply = { mode: 'add' | 'update'; host: string; tabHost?: string; itemName?: string; username?: string; folders: { id: string; name: string }[]; theme: Theme; shortcut?: string }
type CaptureRefused = { refused: 'not_visible' }
type CaptureSave = { kind: 'capture.save'; key: string; folderId: string | null; input: PickInput }   // reply: WriteResult<null> | CaptureRefused | null
type CaptureUpdate = { kind: 'capture.update'; key: string; input: PickInput }   // reply as Save, failing with e.g. `unverified`, `view_only` or `not_found`
type CaptureDismiss = { kind: 'capture.dismiss'; key: string; never: boolean; input?: PickInput }   // reply: CaptureRefused | null; `input` with `never`
type CaptureExpand = { kind: 'capture.expand'; key: string }   // reply: null; the compact bar got focus or a press

/** The bar's height by the top frame's viewport width alone: 64 px from 900 px, 96 px from 420 px, 128 px below. */
const BAR_WIDE_HEIGHT_PX = 64, BAR_MEDIUM_HEIGHT_PX = 96, BAR_NARROW_HEIGHT_PX = 128, BAR_WIDE_MIN_WIDTH_PX = 900, BAR_MEDIUM_MIN_WIDTH_PX = 420
/** Above a third of the viewport height, the host is compact until the user expands it: `barHostHeight(width, height, expanded)`. */
const BAR_COMPACT_HEIGHT_PX = 40, BAR_MAX_VIEWPORT_SHARE = 1 / 3

/** Background → offscreen document (Chrome only). Request/response, reply: boolean. */
type OffscreenWriteClipboard = { kind: 'offscreen.writeClipboard'; text: string }
```

`ContentToBackground` gains `InlineOpen`, `InlineClosed`, `CaptureSubmitted` and `CaptureReshow`, and `page_ready` gains `passwordField` (the top frame has a login form with one, for fill on page load); new unions `BackgroundToContent` (`FillConfirmInsecure`, `FillExecute`, the four host-bound `inline.*` and the five `capture.*`), `ContentToInlineList`, `ContentToNotification`, `InlineListToBackground` and `NotificationToBackground`; `PopupRequest` gains `FillRequest`; the existing `BackgroundToOffscreen` gains `OffscreenWriteClipboard`. Every listener keeps the `unknown` cast at the boundary.

## Risks / Trade-offs

- [Broad `*://*/*` content script triggers store review questions] → Proposal states why autofill needs it; the script is idle until a message or a submit, and the open decision stays tracked in CLAUDE.md.
- [Field heuristics miss unusual forms] → Fill the fields found; the "Unable to autofill" toast and the copy fallback when none is; heuristics live in one module with fixtures so they can grow.
- [Capture queue lost on MV3 worker restart] → Accepted; the alternative is persisting a password, which ADR-002 forbids.
- [`action.openPopup` unavailable] → Popout window fallback.
- [`tldts` bundle size] → Already paid by `src/vault/match.ts`; the matcher adds nothing.
- [Page scripts spoofing `fill.*` or `capture.*` messages] → Background checks `sender.tab`, `sender.frameId`, `sender.url` origin on every arm; bar messages must come from `/notification.html` with a key its host posted to it.
- [A page tricks the user into clicking Save, Update or Never on the bar, or frames its own copy of the bar] → The key and the visibility guard (§ Notification bar); Close stays unguarded, since it can only drop the capture.
- [Whether a bar appears tells the page whether a username is saved and with which password] → Only trusted submissions with user activation, at most one per activation; the bar's height never depends on its mode.
- [A page modal dialog makes the bar unreachable where the popover API is missing (Firefox below 125)] → Accepted; a later change may answer a pending capture from the toolbar popup.
- [A Regular expression rule over a shared item's `url` can hang the background (ReDoS)] → Accepted, the same exposure Bitwarden has.
- [Clipboard clear races with the user's own copy] → Clear unconditionally; checking first needs `clipboardRead` (SECURITY.md § Clipboard).
- [Insecure confirm dialog can be spoofed by the page] → Only affects whether the user is asked; the page cannot obtain the credential without the affirmative reply reaching the background, and the popup and inline list ask in extension UI.
- [A page tricks the user into clicking an invisible inline list] → The visibility guard above: on Chrome IntersectionObserver v2 plus the same-origin ancestor checks, best effort on Firefox.
- [Cross-site login iframes get no inline list on either browser] → Accepted for the mask clickjacking it prevents; fill from the popup, menu and shortcut still works there.
- [Assistive page styling refuses every pick: `zoom` on `<html>` everywhere, a `filter` on `<html>` on Firefox, opacity and transforms only without the popover API] → The list stays open and says so, pointing to the toolbar button.
- [A hostile subframe closes the top frame's list by opening its own, since a tab has one session] → Accepted: it can only close a list, never read or pick from it.
- [Page script focuses a login field to open the list] → The list shows nothing the page can read, and keyboard and pointer picks are guarded.
- [Page CSP blocks the extension iframe] → The host removes itself; fill from the popup, menu and shortcut still works.
- [A formless login whose only submit button is icon-only] → Its anchor becomes the document root, so its submit search may pick an unrelated keyword button; Enter in the password field still captures it.
- [Small login iframe] → The list shrinks to the larger free side down to two rows, and does not open below that; fill from the popup, menu and shortcut still works.

## Open Questions

- Should the save bar offer "Edit" that opens the add form prefilled (Bitwarden's newer bar) in addition to direct Save? Deferred; direct Save with folder choice is implemented here.
- Should locked-vault submits show an "Unlock to save" bar (Bitwarden's newer behaviour) instead of being dropped?
