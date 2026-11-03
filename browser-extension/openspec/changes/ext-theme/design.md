## Context

`popup.css` defines every color as a token on `:root`, with a light set by default and a dark set under `@media (prefers-color-scheme: dark)`, guarded by `:root:not([data-theme='light'])`, plus `[data-theme='light']` and `[data-theme='dark']` overrides that nothing sets yet. No component uses a literal color. So both themes exist and System default already works; this change adds the setting that sets `data-theme`.

## Is System default possible?

Yes, on both targets, with no JavaScript and no permission:

- Extension pages get `prefers-color-scheme` like any page. Chrome reports its own appearance setting, which defaults to the OS; Firefox reports its website appearance setting, which defaults to the Firefox theme and through that the OS.
- The media query re-evaluates while the popup is open, so an OS switch repaints it.
- The background can't see the preference: an MV3 service worker has no `matchMedia`. Nothing in this change needs it there; a themed toolbar icon would.

## Goals / Non-Goals

**Goals:**
- Theme setting with System default, Light and Dark, applied to every popup screen before first paint.
- The Settings tab's section list and an Appearance view, as the base ext-settings extends.

**Non-Goals:**
- Compact mode, animations, quick copy and the Language line (ext-settings).
- Theming content-script UI such as save prompts (ext-autofill reads the same setting when it adds one).
- New colors. The two token sets stay as they are.

## Decisions

- **System default sets no attribute.** `applyTheme('system')` removes `data-theme`, so the existing media query decides. Light and Dark set it, and the existing overrides win. No `matchMedia` listener is needed.
- **Global record in `storage.local` under `settings.global`.** The theme must apply before an account is chosen, so it can't live in the per-account `settings.<id>` record. Account ids are UUIDs, so the key can't collide. ext-settings adds its other global keys to the same record.
- **Types in `src/messages.ts`, storage in `src/settings/global.ts`.** The popup names `Theme` and `GlobalSettings` without importing storage code, as with `MAX_ACCOUNTS`.
- **`settings.get` and `settings.set` are `PopupRequest` kinds** with their own replies (`GlobalSettings`), not `Result` arms, since they don't change the popup's screen. ext-settings widens the reply to its `SettingsSnapshot`.
- **A `localStorage` mirror for the first frame.** The background's reply arrives after the first paint, and later still when the MV3 worker has to wake, so a user with a pinned theme would see the system's theme flash first. `main.tsx` reads `keepiq.theme` from `localStorage` synchronously and applies it before mounting. ADR-004 keeps `browser.storage` out of components, and this is not that: the mirror is a display cache (the theme name, and since ext-settings the compact and animation switches under `keepiq.appearance`), never a source of truth, and `storage.local` stays authoritative. Every `localStorage` access is wrapped, so a missing or throwing store just means following the system until the reply.
- **Other open windows follow through the `storage` event.** Writing the mirror fires `storage` in every other document of the extension's origin, so the pop-out window repaints without a broadcast from the background.
- **One `SettingsProvider` in `<App />`.** It reads `settings.get` once on mount, applies and mirrors it, and listens for the `storage` event; `useSettings` reads it in the Appearance view and the vault cards, and its `update` sends `settings.set` and applies and mirrors the reply. A failed write leaves the theme as it was and shows `not_responding`. (ext-settings replaced the separate `useTheme`.)
- **Settings navigation lives in Shell.** Shell already owns the header's title and back control for the item detail; it keeps the open section the same way and resets it when the tab changes. `Settings.tsx` renders the section list or the open section.
- **Unbuilt sections reuse the `common.later` pattern.** They are `aria-disabled` with a `title`, like the unbuilt item actions, so they stay focusable and the reason can be read.
- **`Select` is a new shared component** in the `.select` style the folder filter uses, with a visible label. ext-settings reuses it for every dropdown.

## Message contract

```ts
export type Theme = 'system' | 'light' | 'dark'
export interface GlobalSettings { theme: Theme }

// PopupRequest
| { kind: 'settings.get' }
| { kind: 'settings.set'; patch: Partial<GlobalSettings> }

// PopupReplies
'settings.get': GlobalSettings
'settings.set': GlobalSettings
```

## Storage

| Key | Area | Holds | Cleared by |
| --- | --- | --- | --- |
| `settings.global` | `storage.local` | `{ theme }` | Uninstall only |
| `keepiq.theme` | extension origin `localStorage` | The theme name, a mirror | Uninstall; rewritten on every read |
| `keepiq.appearance` | extension origin `localStorage` | Compact mode and Show animations, a mirror (ext-settings) | Uninstall; rewritten on every read |

## Risks / Trade-offs

- [The mirror and `storage.local` disagree] → The background's value wins on every open and rewrites the mirror; the worst case is one frame in the old theme.
- [Five disabled sections look unfinished] → They state they arrive later, and they keep the section order fixed as ext-settings adds them.
- [Firefox's System default follows Firefox, not the OS] → That's what the user set Firefox to; the label stays "System default" as in Bitwarden.
