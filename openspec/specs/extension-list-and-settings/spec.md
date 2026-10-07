# extension-list-and-settings Specification

## Purpose
What the Vault list says and offers, and the extension's browser-wide settings.

## Requirements

### Requirement: A list that says what it shows
The Vault tab MUST say what the list shows: "Loading your vault…" until the first list arrives, "Your vault is empty. Add an item with New." for an empty vault, "Nothing matches." with a Clear filters button when the filters leave nothing, and that every item is blocked when none can be opened here. Each card MUST show the item's name, its type and its site, a blocked mark where it applies, Copy for the password of a login, and Open for a web address. The folder filter MUST offer "No folder". Items with the same name MUST keep a fixed order.

#### Scenario: Cards
@e2e exclude Browser-extension popup. Covered by tests/extension/listSettings.spec.js ("shows type and site on each card, copies the password and opens the site").
- **GIVEN** a login for https://example.com
- **WHEN** the Vault tab lists it
- **THEN** its card reads "login · example.com", Copy copies the password for the worker to clear, and Open opens the site

#### Scenario: Empty and no match
@e2e exclude Browser-extension popup. Covered by tests/extension/listSettings.spec.js ("says when nothing matches and clears the filters"), ("says the vault is empty"), ("names the state of the list") and ("filters items in no folder, and orders equal names by id").
- **GIVEN** an empty vault, or a search that matches nothing
- **WHEN** the Vault tab shows
- **THEN** it says so, and Clear filters brings the items back

### Requirement: Settings for autofill, new items and appearance
Settings MUST be grouped: this account, autofill, new items, appearance, Keepiq on the web, and about. The browser-wide settings MUST cover: offer to save new logins, offer to update a changed password, suggest a strong password in sign-up fields, the type of a new item, and the theme (the system's, light or dark). A switched-off offer MUST not be shown, and a page MUST get no password suggestion when that is off. The theme MUST apply as the popup opens. Keepiq on the web MUST say that import and export, notifications and the master password are managed in Keepiq on Nextcloud, with a way to open it. About MUST name the extension's version and the server's, and open the third-party notices. Two quick changes MUST both be kept.

#### Scenario: Offers off
@e2e exclude Browser-extension worker. Covered by tests/extension/listSettings.spec.js ("switches the save offer and password suggestions off").
- **GIVEN** the save offer and suggestions switched off
- **WHEN** a new login is submitted, and a page asks whether to suggest
- **THEN** no save is offered, and the page is told not to suggest

#### Scenario: Type, theme, About
@e2e exclude Browser-extension popup. Covered by tests/extension/listSettings.spec.js ("picks the type of a new item, the theme, and shows About and the web app").
- **GIVEN** Settings
- **WHEN** the user picks note as the type of a new item and the dark theme, quickly one after the other
- **THEN** both are kept, the popup reopens dark, New starts as a note, and About names both versions
