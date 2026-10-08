## Context

Exploration found about 150–200 user-facing strings: most in 23 popup components, about 15 in `entrypoints/popup/errors.ts` (already a code-to-text switch), three in the manifest, and about 10 built in the background. The background ones are the problem: `router.ts` sends the key-changed notice as a sentence, `unlock.ts` and `client.ts` put English (or the server's own message) in `Failure.message`, and `sync.ts` stores an English `blockedReason` on each blocked row in `storage.local`.

## Goals / Non-Goals

**Goals:**
- One catalog per language, typed keys, no hard-coded UI text.
- Adding a language is adding one file.
- English and Dutch from day one, with the same keys.

**Non-Goals:**
- A language picker, more languages, a translation platform.

## Decisions

- **`@wxt-dev/i18n` over a custom catalog.** The manifest name and description can only be translated through the browser's `_locales`, so a custom system would still need it next to its own. The module writes `_locales` from YAML, types the keys during `wxt prepare`, and adds almost no runtime code.
- **The browser picks the language.** `@wxt-dev/i18n` follows `browser.i18n`, which uses the browser's UI language. Bitwarden does the same and has no picker. A picker would mean replacing `browser.i18n` with our own lookup.
- **Catalogs live in `locales/<lang>.yml`.** English is the source; keys are camelCase and describe the place, not the text (`unlockButton`, not `unlock`), so a wording change keeps the key.
- **Named placeholders.** `{name}`, never string concatenation, because word order differs between languages: `` `Launch ${name}` `` becomes `i18n.t('launchItem', { name })`.
- **Sentences with a link** are one message with a `{link}` placeholder. `entrypoints/popup/rich.tsx` splits the translated text on it and puts the element in its place, so translators see the whole sentence.
- **Codes, not text, from the background.** Messages carry a code; the popup owns all wording. `Failure.message` stays for logs only. The server's own error messages are never shown, because they are not translated into the browser's language.
- **Blocked reasons are stored as codes.** `blockedReason` holds `suite_missing`, `suite_compromised` or `suite_revoked`. A row cached before this change holds English text; the popup shows the generic blocked text for any unknown value until the next sync rewrites the row.
- **Formatting reads `browser.i18n.getUILanguage()`.** Relative times, dates and numbers use it, so they match the catalog language. Sorting keeps `Intl.Collator(undefined)`.
- **Plurals use the module's `0` / `1` / `n` forms.** These are enough for English and Dutch. Languages with "few" or "many" forms (Polish, Russian, Czech) need a small `Intl.PluralRules` wrapper when they are added; until then, prefer wording that needs no plural.
- **Shared terms match the web app.** The Dutch for vault, master password and app password follows `../l10n/nl.json`.
- **Tests render in English.** The vitest setup serves `browser.i18n.getMessage` from `locales/en.yml` when WXT's fake browser lacks it, so existing assertions on English text stay. One smoke test renders the popup in Dutch.

## Risks / Trade-offs

- **No per-extension language.** Users who want Keepiq in another language than their browser can't have it. Bitwarden has the same limit.
- **Old cached reasons.** Until the first sync after an update, a blocked row shows the generic text instead of its reason.
- **Dutch quality.** Machine-like Dutch undermines trust in a security product. A native speaker reviews the catalog before release.

## Adding a language

1. Copy `locales/en.yml` to `locales/<lang>.yml` and translate every value.
2. Check the plural forms the language needs.
3. Run `npm test`; the parity test lists missing or extra keys.
