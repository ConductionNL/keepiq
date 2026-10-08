## 1. Setup

- [ ] 1.1 Add `@wxt-dev/i18n` and its module to `wxt.config.ts`, set `default_locale: 'en'`, and create `locales/en.yml` and `locales/nl.yml`
- [ ] 1.2 Localise the manifest `name`, `description` and `action.default_title` with `__MSG_…__`, keeping the `(DEV)` suffix on dev builds
- [ ] 1.3 Serve `browser.i18n.getMessage` from `locales/en.yml` in the vitest setup when the fake browser lacks it
- [ ] 1.4 Add `locales/locales.test.ts`: the same keys and, per key, the same placeholders in every catalog

## 2. Codes from the background

- [ ] 2.1 `src/vault/sync.ts`: store `blockedReason` as `suite_missing`, `suite_compromised` or `suite_revoked`; update `sync.test.ts`
- [ ] 2.2 `src/background/router.ts`: send the key-changed notice and the unknown-error reply as codes; update `router.test.ts`
- [ ] 2.3 `src/vault/unlock.ts` and `src/api/client.ts`: no display text in `Failure`, no server message passed on
- [ ] 2.4 `hooks/usePopupState.ts`: turn result codes and notice codes into text through `errors.ts`

## 3. Popup text

- [ ] 3.1 Move `entrypoints/popup/errors.ts` to the catalog, with a generic text for unknown codes
- [ ] 3.2 Add `entrypoints/popup/rich.tsx` for a sentence with a `{link}` placeholder, with its test
- [ ] 3.3 Move the text of every view and component to the catalog: `App`, `Shell`, `Unlock`, `AddAccount`, `LogInAgain`, `AccountSwitcher`, `VaultList`, `ItemDetail`, `ItemCard`, `Menu`, `TotpCode`, `TabBar`, `TypeFilterChips`, `useClipboard` and the rest
  - Concatenated labels become named placeholders
  - Counts use the plural forms
- [ ] 3.4 `relative-time.ts` and the dates in `ItemDetail.tsx` use `browser.i18n.getUILanguage()`
- [ ] 3.5 Add a lint rule against literal text in JSX, if one is available for the current ESLint setup

## 4. Dutch

- [ ] 4.1 Translate `locales/nl.yml`, matching the web app's `../l10n/nl.json` for shared terms
- [ ] 4.2 Add a popup smoke test that renders the unlock screen in Dutch
- [ ] 4.3 Have a native speaker review the Dutch

## 5. Docs and checks

- [ ] 5.1 `ARCHITECTURE.md`: an i18n section; `README.md`: adding a language; `CLAUDE.md`: the catalog rule; `docs/browser-extension/using.md`: a Language line
- [ ] 5.2 `npm test`, typecheck, lint, both builds, and check that `_locales/en` and `_locales/nl` are in both outputs
- [ ] 5.3 Walk through Chrome with `--lang=nl` and Firefox with `intl.locale.requested=nl`
