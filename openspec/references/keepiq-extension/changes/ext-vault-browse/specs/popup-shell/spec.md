## ADDED Requirements

### Requirement: Popup layout with bottom tab bar
The popup SHALL consist of a fixed header, a scrolling content area and a fixed bottom tab bar with the tabs Vault, Generator, Send and Settings in that order, each with an icon and label as in Bitwarden. Vault is the default tab. The header shows the title of the current view, the pop-out button and the account avatar control supplied by ext-accounts-and-unlock at the top right.

#### Scenario: Fresh unlocked popup
- **GIVEN** the vault is unlocked
- **WHEN** the popup opens
- **THEN** the Vault tab is active and its content fills the area between header and tab bar
- **AND** the header shows the pop-out button and the account avatar

#### Scenario: Switching tabs
- **GIVEN** the Vault tab is active
- **WHEN** the user activates Settings
- **THEN** the Settings tab becomes active, the content area swaps and the header title updates

### Requirement: Placeholder tabs until their changes land
The Generator, Send and Settings tabs SHALL exist in the tab bar from this change and SHALL render a placeholder view stating that the feature arrives in a later update, until ext-generator, ext-send and ext-settings replace them.

#### Scenario: Send tab before ext-send
- **GIVEN** ext-send is not implemented
- **WHEN** the user activates Send
- **THEN** the content area shows the placeholder and nothing else

### Requirement: Last tab is remembered for the browser session
The extension SHALL store the last active tab under `popup:lastTab` in `storage.session` when available (guarded, per WXT-AND-BROWSERS.md) and in background memory otherwise, and restore it when the popup reopens. The value is cleared on lock, logout and browser restart. Only the tab is restored, never the item detail view or filter state.

#### Scenario: Reopen after closing on Settings
- **GIVEN** the user closed the popup while on the Settings tab
- **WHEN** the popup reopens in the same browser session with the vault unlocked
- **THEN** the Settings tab is active

#### Scenario: Reopen after lock
- **GIVEN** the vault locked since the popup was last open
- **WHEN** the user unlocks
- **THEN** the Vault tab is active

### Requirement: Locked and logged-out gating
While the vault is locked the popup SHALL show the unlock view from ext-accounts-and-unlock and SHALL hide the tab bar. While no account is logged in the popup SHALL show the login view and SHALL hide the tab bar. This follows Bitwarden, which offers no tab while locked. The one deliberate deviation is a "Generate a password" link on the unlock view that opens the generator view without the tab bar (owned by ext-generator), because generation needs no key material.

#### Scenario: Popup opens locked
- **GIVEN** an account is logged in and the vault is locked
- **WHEN** the popup opens
- **THEN** the unlock view fills the popup and no tab bar is rendered

#### Scenario: Lock while a tab is open
- **GIVEN** the Vault tab is showing an item detail
- **WHEN** the vault locks (timeout or manual)
- **THEN** the popup replaces its content with the unlock view and drops every decrypted value from memory

### Requirement: Popup dimensions
The popup SHALL be 380 px wide and SHALL grow with its content up to 600 px high, after which the content area scrolls while header and tab bar stay fixed, as in Bitwarden.

#### Scenario: Long vault list
- **GIVEN** 300 items in the vault
- **WHEN** the Vault tab renders
- **THEN** the popup is 600 px high, the list scrolls, and header and tab bar remain visible

### Requirement: Pop out to a standalone window
The popup SHALL offer a pop-out button that opens the popup page in a standalone window via `browser.windows.create({ type: 'popup', width: 380, height: 630, url: '<popup url>?popout=1&tabId=<id>' })`, then closes itself. In the popped-out window the layout fills the window width and the pop-out button is hidden. The `tabId` parameter carries the tab that was active when the button was pressed so autofill suggestions keep matching that tab.

#### Scenario: Pop out from the toolbar popup
- **GIVEN** the popup is open from the toolbar on a tab showing `https://example.com`
- **WHEN** the user activates pop out
- **THEN** a standalone window opens showing the same view
- **AND** the toolbar popup closes
- **AND** the autofill suggestions in the window still match `example.com`

#### Scenario: Already popped out
- **GIVEN** the popup page runs in a standalone window
- **WHEN** the header renders
- **THEN** the pop-out button is not shown

### Requirement: Active tab context in the header
When the popup opens on a tab with an `http:` or `https:` URL, the header SHALL show that tab's host under the view title. When the tab has no such URL (new tab page, browser pages, file URLs) no host is shown and the autofill suggestions section is hidden.

#### Scenario: Popup on a website
- **GIVEN** the active tab is `https://app.example.com/login`
- **WHEN** the popup opens on the Vault tab
- **THEN** the header shows `app.example.com`

#### Scenario: Popup on a browser page
- **GIVEN** the active tab is the browser's new tab page
- **WHEN** the popup opens
- **THEN** no host is shown in the header

### Requirement: Theme tokens follow the system
The popup SHALL define its colours as CSS custom properties on `:root` with light values, dark values under `@media (prefers-color-scheme: dark)`, and an override for `:root[data-theme="light"]` and `:root[data-theme="dark"]` so ext-settings can add a theme switch without touching component styles. No theme preference is stored by this change.

#### Scenario: System dark mode
- **GIVEN** the operating system is in dark mode and no `data-theme` attribute is set
- **WHEN** the popup renders
- **THEN** dark token values apply and the browser paints the popup frame dark via `color-scheme`

#### Scenario: Forced light theme
- **GIVEN** `<html data-theme="light">` is set
- **WHEN** the system is in dark mode
- **THEN** light token values apply

### Requirement: Popup is stateless across opens
The popup document SHALL hold no state that must survive its closing other than what this spec routes to `storage.session`. Filter state, scroll position and the open detail view live in popup memory and reset when the popup closes. The background is the source of truth for vault, sync and unlock state.

#### Scenario: Close and reopen mid-search
- **GIVEN** the user typed a search term and opened an item
- **WHEN** the popup closes and reopens
- **THEN** the Vault tab shows the unfiltered list at the top
