## Context

By the time this change lands, the earlier changes provide:
- `src/menus.ts`: the "Keepiq" menu on `editable` contexts, rebuilt from `removeAll` on install, startup, tab activation, active tab URL change, window focus and every broadcast; item entries `autofill:<id>`, `copy-user:<id>`, `copy-pass:<id>`, `copy-code:<id>`; Open Keepiq and Unlock vault opened before any `await` (ext-autofill, ext-totp-autofill);
- the card and identity submenus with `card:<id>` and `identity:<id>` children, and their fill branch with its in-page questions (ext-card-identity-fill);
- `fill()` in `src/autofill/fill.ts`, which answers `NONE` alike for no field, a declined question, a lock and a decrypt failure;
- the save bar: `notification.html` in an iframe that `notification-host.ts` places in the top frame (ext-autofill);
- `ItemForm` with the add flow and `TypeSelect` (ext-vault-edit), and `openPopupOrPopout()` in `src/browser-action.ts`.

## Goals / Non-Goals

**Goals:**
- The menu is there wherever the user right-clicks a page, and every entry opens.
- A fill that cannot happen says why.
- A login, card or identity can be added from the menu.

**Non-Goals:**
- Copy custom field name, filling a generated password, usernames in titles (proposal).

## Decisions

- **Explicit page contexts:** `page`, `frame`, `selection`, `link`, `editable`, `image`, `video`, `audio`. Chrome's `all` also covers the toolbar icon's own menu. Firefox's `password` is inside `editable`.
- **Names only.** Chromium's `MenuManager::WriteToStorage` (`chrome/browser/extensions/menu_manager.cc`) saves every item of an extension with a lazy context, a service worker included, to the extension state store on disk. A decrypted username in a title would break ADR-002's "nothing decrypted is written to any storage". Names are plaintext metadata already.
- **Every entry is a parent.** The one-item shortcut of `perItem()` goes. Without rows a parent gets one child, `<prefix>-none`, `enabled: false`. A disabled parent read as a turned-off feature.
- **Ids.**
  - Children stay `autofill:<id>`, `copy-user:<id>`, `copy-pass:<id>`, `copy-code:<id>`, `card:<id>`, `identity:<id>`.
  - The Add entries are `add:login`, `add:card`, `add:identity`, and Open Keepiq is `open:login`, `open:card`, `open:identity`, since an id is unique per menu.
  - The separators are `autofill-separator`, `card-separator`, `identity-separator`, `separator-1` and `separator-2`.
- **Card and identity parents always show.** ext-card-identity-fill left them out for an empty vault; here they hold "No cards available" or "No identities available" and still offer Add.
- **"No field" is its own result.** `fill()` answers `noFields: true` when the item fits no recorded frame of the tab, or every frame it sent `fill.execute` answered `none` without a code. A declined question, a lock, a missing key or a decrypt failure stay plain `NONE`. The card and identity branch answers `noFields: true` when no probed frame reported a role.
- **The notice is in the page, as a code.** After a menu fill with `noFields`, the background sends `notice.show { notice }` to frame 0 (`no_login_form`, `no_card_form`, `no_identity_form`). `notification-host.ts` hosts `notification.html?notice=<code>`, which words it with `i18n.t()`.
  - Placement: its own host in the bar's corner, below the save bar when one is open. A later notice replaces it, and navigation removes it with the page.
  - Behaviour: `role="status"` and a Close button; it never takes focus. It closes after 8 seconds, timed only while neither pointer nor focus is in it.
  - It offers no action, so it needs no visibility guard. A tab without a content script gets no notice.
- **The Add entries pass an intent in memory.** Like Open Keepiq, an Add click is handled before any `await`, since Firefox below 149 opens the popup only from the click. It sets `{ add, tabId, at }` in background memory, then calls `openPopupOrPopout(tab.id)`.
  - The popup and the pop-out send `popup.intent` once on mount. The background answers and forgets the intent.
  - The answer is `null` when the intent is older than 10 seconds or the active account is not unlocked. A lock or an account switch clears it.
  - Nothing reaches storage. A worker restart in between opens the vault list.
- **A fixed-type add form.** `ItemForm` takes `fixedType: 'login' | 'card' | 'identity'`. It resolves the system type by name, hides `TypeSelect` and titles the form "New login", "New card" or "New identity". Otherwise it is the add form: the tab's URL for a login, the default folder, Save and Cancel back to the vault list.
- **Fill from anywhere uses the popup's frame choice.** A menu fill picks frames by `framesFor()` as the popup's Fill does, wherever the user clicked. The right-clicked element plays no part.

## Lock audit

| Surface or payload | While locked |
| --- | --- |
| Menu | Only "Unlock vault" (ext-autofill) |
| Add intent | Cleared by the lock; `popup.intent` answers `null` |
| Notice | Never sent: `act()` returns before any fill without an unlocked account |

## Storage

| Datum | Lives in | Cleared by |
| --- | --- | --- |
| Menu titles: item names, fixed texts | Chrome's extension state on disk; Firefox memory | The next rebuild |
| Add intent `{ add, tabId, at }` | Background memory | Read by the popup, 10 seconds, lock, account switch, worker stop |
| Notice code | The notice page's URL | Close, 8 seconds, navigation |

## Module layout

- `src/menus.ts`: contexts, the tree, the empty lines, `add:*` in `onMenuClicked`, `notice.show` after a fill with `noFields`, the intent.
- `src/autofill/fill.ts`: `noFields` on `FillResult`; the card and identity branch likewise.
- `src/autofill/notification-host.ts`, `entrypoints/content.ts`: the notice host.
- `entrypoints/notification/`: the notice mode.
- `src/background/requests.ts`: `popup.intent`.
- `entrypoints/popup/views/Shell.tsx`, `ItemForm.tsx`: open the fixed-type form from the intent.
- `locales/en.yml`, `locales/nl.yml`: every new text; `contextMenu.autofill` and `contextMenu.noMatches` renamed.

## Message contract

```ts
/** `noFields`: no frame of the tab had a field for the item. */
type FillResult = { filled: FillOutcome; code: boolean; needsInsecureConfirm?: boolean; noFields?: true }
type NoticeCode = 'no_login_form' | 'no_card_form' | 'no_identity_form'
/** Background → the top frame. Fire-and-forget. */
type NoticeShow = { kind: 'notice.show'; notice: NoticeCode }
/** Popup → background, once on mount. Reply: the add form to open, or `null`. */
type PopupIntent = { kind: 'popup.intent' }
type PopupIntentReply = { add: 'login' | 'card' | 'identity' } | null
```

## Risks / Trade-offs

- [No content script in the tab (a PDF viewer, a tab open before install or update, a store page)] → No notice can show. The fill fails silently there, as today.
- [Two items with the same name look alike] → Open Keepiq shows them with their usernames; the order is last used, then name.
- [Another popup opens within 10 seconds of an Add click and takes the intent] → It opens on that add form, which Cancel leaves.
- [The menu on every right-click is noise for users who never use it] → The "Enable context menu options" setting hides it.

## Open Questions

None.
