## 1. Background

- [x] 1.1 Add `Theme`, `GlobalSettings`, `THEMES` and the `settings.get` and `settings.set` request kinds and replies to `src/messages.ts`
- [x] 1.2 Create `src/settings/global.ts`: read `settings.global` with invalid values as defaults, and a validated patch write, with its test
- [x] 1.3 Handle both kinds in `src/background/requests.ts`; `settings.get` is not user activity
  - Tests cover a locked vault, an invalid patch and the reply shape

## 2. Applying the theme

- [x] 2.1 Create `entrypoints/popup/theme.ts`: apply a theme to `data-theme`, and read and write the `keepiq.theme` mirror, every access wrapped, with its test
- [x] 2.2 Apply the mirrored theme in `main.tsx` before mounting
- [x] 2.3 Create `hooks/useTheme.ts` and run it in `<App />`: read `settings.get` once, apply and mirror it, follow the `storage` event
- [x] 2.4 Update the theme comment in `popup.css`; the token sets stay as they are

## 3. Settings tab

- [x] 3.1 Create `components/Select.tsx` in the `.select` style, with a visible label, and add the section icons to `Icon.tsx`
- [x] 3.2 Create `hooks/useSettings.ts` over `settings.get` and `settings.set`, applying and mirroring the theme on a reply
- [x] 3.3 Create `views/settings/SettingsIndex.tsx`, `Appearance.tsx` and `Settings.tsx`, and wire them into Shell with the title and back control
  - Unbuilt sections are `aria-disabled` with "Available in a later update"
  - Switching tabs returns to the section list
- [x] 3.4 Add the text to `locales/en.yml` and `locales/nl.yml`
- [x] 3.5 Extend `fakeBackground` and add view tests: the section list, opening Appearance, changing the theme, a failed write, and the theme on the unlock screen

## 4. Docs and checks

- [x] 4.1 Trim ext-settings to build on this change; add the storage rows to `ARCHITECTURE.md`, the change to `openspec/README.md`, and a Theme section to `../docs/browser-extension/using.md` and `privacy.md`
- [x] 4.2 Run `npm test`, `npm run typecheck`, `npm run lint`, `npm run build` and `npm run build:firefox`
- [ ] 4.3 Walk through Chrome and Firefox: each theme on the unlock and vault screens, an OS switch on System default, a pop-out window following a change, and no flash on reopen
