# extension-popup-shell Specification

## Purpose
The frame of the extension popup: the tab bar, the pop-out window, the remembered tab and the theme.

## Requirements

### Requirement: A tab bar at the bottom
The unlocked popup MUST show its tabs in a bar fixed to the bottom: This site, Vault, Generator, Send and Settings. The header with the account and the lock stays fixed at the top, and only the content between them scrolls. The popup is 380 pixels wide and at most 600 pixels high. The tabs carry text labels only; the "This site" tab is kept as its own tab rather than folded into Vault as suggestions.

#### Scenario: Settings from the tab bar
@e2e exclude Browser-extension popup. Covered by tests/extension/popupShell.spec.js ("opens Settings from the tab bar").
- **GIVEN** an unlocked popup
- **WHEN** the user picks Settings in the tab bar
- **THEN** the settings view opens

### Requirement: Reopen on the last tab
The popup MUST reopen on the tab it was last on, for the rest of the browser session only. The remembered tab MUST be forgotten when the account locks, so an unlock starts on This site.

#### Scenario: Last tab, then a lock
@e2e exclude Browser-extension popup. Covered by tests/extension/popupShell.spec.js ("reopens on the last tab, and on This site after a lock").
- **GIVEN** the popup was last on Generator
- **WHEN** it opens again, and later opens after a lock and an unlock
- **THEN** it opens on Generator, and after the lock on This site

### Requirement: Pop out into a window
The popup MUST offer to open itself in its own window, which stays open while the user switches tabs. The window MUST act on the tab it was opened over: This site, the generator's site and fill all use that tab, and fill still refuses when that tab moved to another site. The pop-out button is hidden inside the window, and the window fills its own width.

#### Scenario: Pop out
@e2e exclude Browser-extension popup. Covered by tests/extension/popupShell.spec.js ("pops out into a window pinned to the current tab").
- **GIVEN** an unlocked popup over a website
- **WHEN** the user picks pop out
- **THEN** a popup window opens pinned to that tab and the small popup closes

#### Scenario: Fill from the window
@e2e exclude Browser-extension popup. Covered by tests/extension/popupShell.spec.js ("fills the pinned tab when popped out, not the active one") and ("refuses to fill a pinned tab that moved to another site").
- **GIVEN** a popped-out window pinned to a tab on example.com, while another tab is active
- **WHEN** the user fills a login
- **THEN** the pinned tab is filled, and a pinned tab that moved to another site is refused

### Requirement: Say when there is no website
When the tab is not an http or https page, This site MUST say "Open a website to see its logins." and offer nothing to fill.

#### Scenario: A browser page
@e2e exclude Browser-extension popup. Covered by tests/extension/popupShell.spec.js ("says to open a website when the tab is not one").
- **GIVEN** the active tab shows a browser page
- **WHEN** the popup opens
- **THEN** This site shows the hint and no logins

### Requirement: Follow the light or dark theme
The popup MUST take its colours from theme tokens, use dark values when the system prefers dark, and follow an explicit light or dark theme on the root element over the system preference.

#### Scenario: Dark system
@e2e exclude Visual theming of the extension popup. Checked live in Chromium with the dark colour scheme emulated.
- **GIVEN** a system set to dark
- **WHEN** the popup opens
- **THEN** it shows the dark tokens
