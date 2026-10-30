# Tasks: Keepiq takes its look and its language from Nextcloud

## 1. House style

- [ ] 1.1 Replace the fixed `backgroundColor` values (`#21468B`, `#3b82f6`, `#6b7280`) and white text of the dashboard quick-action tiles in `src/manifest.json` with Nextcloud theme variables (`--color-primary-element`, `--color-primary-element-text`). Verify: the manifest validation test passes and the tiles take the primary colour with thematiq on.
- [ ] 1.2 Read `theme_color` and `background_color` in `lib/Controller/WebManifestController.php` and the theme-color meta in `templates/index.php` from Nextcloud theming (`OCA\Theming\ThemingDefaults::getColorPrimary()`), keeping `#21468B` only as the fallback when theming is off. Verify: PHPUnit `WebManifestControllerTest` with theming on and off.
- [ ] 1.3 Add a stylelint rule that rejects a hex colour outside a `var()` fallback in `src/**/*.vue` and `src/**/*.css`. Verify: `npm run stylelint` passes on development plus the two fixes above.

## 2. Language

- [ ] 2.1 Add a test that every `t('keepiq', ...)` key used in `src/` has an entry in `l10n/nl.json`. Verify: the test fails on a deliberately missing key.

## 3. Matrix

- [ ] 3.1 When the tasks are done, archive this change and point `clients-16` and `clients-23` in `openspec/parity/capabilities.json` at `openspec/specs/platform-properties`.

## Acceptance criteria

- With thematiq on, no Keepiq surface keeps a fixed brand colour.
- A user who sets Nextcloud to Dutch sees Keepiq in Dutch.
