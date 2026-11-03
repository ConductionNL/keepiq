## ADDED Requirements

### Requirement: The look comes from the Nextcloud theme

Keepiq SHALL style every page, dialog and dashboard widget through Nextcloud theme variables, so the theme set by Nextcloud theming or thematiq (NL Design System tokens) applies without a code change. A colour value SHALL appear in `src/` only as the fallback of a `var()`. The web app manifest's theme and background colours SHALL follow the instance's primary colour. Covers capability row `clients-16`.

#### Scenario: A municipality applies its house style

- **GIVEN** thematiq is configured with the design tokens of Zuiddrecht
- **WHEN** a vault user opens the Keepiq dashboard and a secret
- **THEN** buttons, links, quick-action tiles and headings use the Zuiddrecht colours

#### Scenario: The installed web app follows the theme

- **GIVEN** Nextcloud theming sets the primary colour to green
- **WHEN** a browser reads the Keepiq web app manifest
- **THEN** its `theme_color` is that green

#### Scenario: A hard-coded colour is added

- **GIVEN** a component in `src/` with a literal hex colour outside a `var()` fallback
- **WHEN** the style lint runs
- **THEN** it reports the colour

### Requirement: The language follows the user's Nextcloud setting

Keepiq SHALL show its interface in the language each user picks in their Nextcloud personal settings, through Nextcloud's translation layer, with English as the source language and Dutch always complete, as the fleet spec `hydra/openspec/specs/i18n-infrastructure` defines. Keepiq SHALL NOT keep a language setting of its own. Covers capability row `clients-23`.

#### Scenario: A user switches to Dutch

- **GIVEN** a vault user whose Nextcloud language is English
- **WHEN** the user sets Nextcloud to Dutch and reopens Keepiq
- **THEN** the navigation, the secret list and the dialogs are in Dutch

#### Scenario: A string without a Dutch translation

- **GIVEN** a `t('keepiq', ...)` key in `src/` with no entry in `l10n/nl.json`
- **WHEN** the translation test runs
- **THEN** it fails and names the key
