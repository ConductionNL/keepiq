## 1. Setup

- [x] 1.1 Add `@wxt-dev/i18n` and its module to `wxt.config.ts`, set `default_locale: 'en'`, and create `locales/en.yml` and `locales/nl.yml`
- [x] 1.2 Localise the manifest `description` with `__MSG_extensionDescription__`; the name and toolbar title stay "Keepiq", with `(DEV)` on dev builds
- [x] 1.3 Serve `browser.i18n` from the YAML catalogs in the vitest setup (`src/testing/i18n.ts`), with `setLocale` for Dutch tests
- [x] 1.4 Add `locales/locales.test.ts`: the same keys and, per key, the same placeholders in every catalog, and no keys that differ only in case

## 2. Codes from the background

- [x] 2.1 `src/vault/sync.ts`: store `blockedReason` as `suite_missing`, `suite_revoked`, `suite_compromised` or `migration_failed`, also for rows the fallback sends blocked; `toItemMeta` drops a reason that is not a code
- [x] 2.2 `src/background/router.ts`: send the notice as a `NoticeCode`; `Result` carries no message
- [x] 2.3 `src/vault/unlock.ts` and `src/api/client.ts`: no display text in `Failure`, no server message passed on
- [x] 2.4 `hooks/usePopupState.ts` and `hooks/useMessage.ts`: words come from `errors.ts`; no reply is `not_responding`

## 3. Popup text

- [x] 3.1 Move `entrypoints/popup/errors.ts` to the catalog, with `noticeText`, `blockedText` and a generic text for unknown codes
- [x] 3.2 Add `entrypoints/popup/i18n.tsx` with `rich` for a sentence with a link, `fieldAction` and `language`, with its test
- [x] 3.3 Move the text of every view and component to the catalog
  - Concatenated labels become named placeholders
  - The TOTP countdown uses the plural forms
- [x] 3.4 `relative-time.ts`, the dates in `ItemDetail.tsx`, the row count and `<html lang>` use the catalog's `language`
- [x] 3.5 Add a `no-restricted-syntax` rule against letters in JSX text and literal text props in popup components

## 4. Dutch

- [x] 4.1 Translate `locales/nl.yml`, following the mobile app's terms and tone
- [x] 4.2 Add a popup test that renders the unlock screen in Dutch, notice and error included
- [ ] 4.3 Have a native speaker review the Dutch

## 5. Docs and checks

- [x] 5.1 `ARCHITECTURE.md`: an i18n section; `README.md`: adding a language; `CLAUDE.md`: the catalog rule; `docs/browser-extension/using.md`: a Language line
- [x] 5.2 `npm test`, typecheck, lint, both builds and `web-ext lint`, and check that `_locales/en` and `_locales/nl` are in both outputs
- [ ] 5.3 Walk through Chrome with `--lang=nl` and Firefox with `intl.locale.requested=nl`
