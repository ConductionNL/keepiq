---
kind: code
depends_on: [ext-vault-browse]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-i18n
---

## Why

The extension is English only. Its text is hard-coded across the popup, and a few background modules send finished English sentences to it, one of which is stored with the cached vault. Keepiq's web app ships 37 languages and the mobile app English and Dutch; the company i18n ADR sets English and Dutch as the minimum for every client with a UI.

Multiple languages are the goal. This change lays the groundwork and ships the first two: English and Dutch.

## What Changes

- All user-facing text comes from a message catalog per language, built on the browser's own `_locales` through WXT's `@wxt-dev/i18n` module.
- The language follows the browser's UI language, with English as the fallback. There is no picker in the extension, as in Bitwarden.
- The manifest description is localised, so the extensions page and the stores show it in the user's language. The name "Keepiq" is a brand and stays.
- The background sends codes, never display text: error messages, the key-changed notice and the blocked-item reason become codes the popup turns into text.
- Dates, relative times and numbers are formatted in the catalog's language.
- English and Dutch catalogs with exactly the same keys, guarded by a test, and a lint rule against hard-coded text in the popup.

## Capabilities

### New Capabilities
- `i18n`: the message catalogs, language choice, localised manifest, formatting, and the codes-not-text rule between background and popup.

### Modified Capabilities

None. `openspec/specs/` is empty today. The Language line in ext-settings reads the language this change picks.

## Non-goals

- A language picker in the extension.
- Languages beyond English and Dutch.
- A translation platform such as Weblate or Transifex.
- Reusing the web app's catalogs; shared terms are matched by hand.

## Impact

- New: `locales/en.yml`, `locales/nl.yml`, `locales/locales.test.ts`, `entrypoints/popup/i18n.tsx`, `src/testing/i18n.ts`, `vitest.setup.ts`.
- Edited: `wxt.config.ts` (module, `default_locale`, `__MSG_` description), `package.json`, `eslint.config.mjs`, `vitest.config.ts`, `src/vault/sync.ts`, `src/vault/store.ts`, `src/vault/types.ts`, `src/vault/payloads.ts`, `src/background/router.ts`, `src/vault/unlock.ts`, `src/api/client.ts`, `src/messages.ts`, `entrypoints/popup/errors.ts`, `relative-time.ts`, `main.tsx`, `hooks/usePopupState.ts`, `hooks/useMessage.ts` and every popup view and component with text.
- New dependency: `@wxt-dev/i18n`.
- No new permissions. `browser.i18n` needs none.
