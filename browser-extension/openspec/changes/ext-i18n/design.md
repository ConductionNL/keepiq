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
- **Catalogs live in `locales/<lang>.yml`.** English is the source. Keys are grouped by screen (`unlock.submit`, `vault.syncNow`) and describe the place, not the text, so a wording change keeps the key. Message names are case-insensitive in the browser, so no two keys may differ only in case.
- **The brand stays.** The manifest name and toolbar title are "Keepiq" in every language; only the description is translated.
- **Named placeholders.** `{name}`, never string concatenation, because word order differs between languages: `` `Launch ${name}` `` becomes `i18n.t('item.launch', { name })`.
- **Sentences with a link** are one message with a `{link}` placeholder. `rich()` in `entrypoints/popup/i18n.tsx` splits the translated text on it and puts the element in its place, so translators see the whole sentence.
- **Field actions keep their capitals where the label starts the sentence.** "Show {field}" lower-cases "Password"; "{field} tonen" keeps "Wachtwoord". `fieldAction()` decides from the template.
- **Codes, not text, from the background.** `Result` carries only a code, and the notice is a `NoticeCode`; the popup owns all wording. `Failure.message` stays for logs only. The server's own error messages are never shown, because they are not in the browser's language.
- **Blocked reasons are stored as codes.** `blockedReason` holds `suite_missing`, `suite_revoked`, `suite_compromised` or `migration_failed`, also for rows the fallback list sends already blocked with English text. A row cached by an older version holds English text; the popup shows the generic blocked text for any unknown value until the next sync rewrites the row.
- **Formatting follows the catalog, not the browser.** Each catalog names its own language (`language: nl`); dates, relative times, numbers and `<html lang>` use it, so a French browser gets English dates beside English text. Sorting keeps `Intl.Collator(undefined)`.
- **Plurals use the module's `0` / `1` / `n` forms.** These are enough for English and Dutch. Languages with "few" or "many" forms (Polish, Russian, Czech) need a small `Intl.PluralRules` wrapper when they are added; until then, prefer wording that needs no plural.
- **Dutch follows the mobile app.** It uses je, kluis, hoofdwachtwoord, app-wachtwoord and webapp, like `../mobile`'s `values-nl/strings.xml`. The web app's own Dutch mixes "masterwachtwoord" and "hoofdwachtwoord", and u and je.
- **Lint keeps it that way.** `no-restricted-syntax` fails on letters in JSX text and on literal `label`, `title`, `aria-label`, `placeholder` and similar props in popup components.
- **Tests render in English.** The vitest setup serves `browser.i18n` from the YAML catalogs (`src/testing/i18n.ts`), so existing assertions on English text stay; `setLocale('nl')` switches a test to Dutch.

## Risks / Trade-offs

- **No per-extension language.** Users who want Keepiq in another language than their browser can't have it. Bitwarden has the same limit.
- **Old cached reasons.** Until the first sync after an update, a blocked row shows the generic text instead of its reason.
- **Dutch quality.** Machine-like Dutch undermines trust in a security product. A native speaker reviews the catalog before release.

## Adding a language

1. Copy `locales/en.yml` to `locales/<lang>.yml`, translate every value, and set `language` to the language's tag.
2. Add the tag to `LOCALES` in `src/testing/i18n.ts`.
3. Check the plural forms the language needs.
4. Run `npm test`; the parity test lists missing or extra keys and placeholders.
