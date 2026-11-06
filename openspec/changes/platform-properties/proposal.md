---
kind: code
---

# Keepiq takes its look and its language from Nextcloud

## Why

Two capability rows describe how Keepiq looks and speaks on every page, not a page of its own. Both are built, but no Keepiq requirement says them, so no row can cite one and nothing stops a later change from hard-coding a colour or a string.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `clients-16` | Theme the vault in the organisation's own house style. | built, owner thematiq: styles use Nextcloud theme variables; two surfaces still use fixed colours |
| `clients-23` | Use the vault in your own language, chosen per user from many translations. | built: 37 languages in `l10n/`, the language is the user's Nextcloud setting; fleet spec `hydra/openspec/specs/i18n-infrastructure` |

## What changes

- A new capability `platform-properties` with two requirements: the look comes from the Nextcloud theme, and the language comes from the user's Nextcloud setting.
- Two fixed colours the house-style requirement forbids are replaced by theme values: the dashboard quick-action tiles (`src/manifest.json`) and the web app manifest's theme colour (`lib/Controller/WebManifestController.php`, `templates/index.php`).
- A style lint rule keeps bare hex colours out of `src/`.

## Capabilities

### New Capabilities

- `platform-properties`: how Keepiq follows the organisation theme and the user's language.

### Modified Capabilities

- None.

## Screens

None. Both are properties of every page; see `screens.md`.

## Out of scope

- Theming itself, which Nextcloud theming and thematiq own.
- The translation pipeline, which the fleet i18n spec owns.
