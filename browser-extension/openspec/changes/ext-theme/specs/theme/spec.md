## ADDED Requirements

### Requirement: Theme setting
The extension SHALL offer the Theme options System default, Light and Dark, default System default. The value is global, not per account, and is stored in `storage.local` under `settings.global`. Nothing clears it automatically: it survives logout, account removal and browser restart, and goes with the extension's uninstall. A stored value that is not one of the three options is read as System default.

#### Scenario: Fresh profile
- **GIVEN** no `settings.global` record exists
- **WHEN** the popup opens
- **THEN** Theme reads as System default and the popup follows the system

#### Scenario: Invalid stored value
- **GIVEN** `settings.global` holds the theme `"nord"`
- **WHEN** the background reads the settings
- **THEN** Theme reads as System default

#### Scenario: Survives logout
- **GIVEN** Theme is Dark and one account is added
- **WHEN** the user logs out of that account
- **THEN** the add-account screen is dark

### Requirement: System default follows the browser
With Theme on System default, the extension SHALL follow the `prefers-color-scheme` media feature, which reflects the browser's light or dark preference and by default the OS. A change of that preference while the popup is open MUST repaint it without reopening.

#### Scenario: OS switches while open
- **GIVEN** Theme is System default and the OS is light
- **WHEN** the OS switches to dark with the popup open
- **THEN** the popup repaints dark

#### Scenario: Pinned theme ignores the OS
- **GIVEN** Theme is Light
- **WHEN** the OS switches to dark with the popup open
- **THEN** the popup stays light

### Requirement: Theme applies everywhere at once
The extension SHALL apply the theme to every popup screen, the unlock, add-account, log-in-again and account-switcher screens included, and to the pop-out window. A change applies to the open popup immediately and to any other open extension page without reopening it.

#### Scenario: Change from Appearance
- **GIVEN** Theme is System default and the OS is light
- **WHEN** the user selects Dark
- **THEN** the popup repaints dark without reopening
- **AND** the unlock screen is dark on the next open

#### Scenario: Pop-out open beside the popup
- **GIVEN** a pop-out window is open
- **WHEN** the user changes the theme in the toolbar popup
- **THEN** the pop-out window repaints in the new theme

### Requirement: No flash of the other theme
The extension SHALL paint the first frame of the popup in the stored theme. To do that, the popup mirrors the theme into the extension origin's `localStorage` under `keepiq.theme`, reads it before mounting, and replaces it with the background's value once that arrives. The mirror holds only the theme name. When `localStorage` is unavailable, the first frame follows the system and the background's value applies when it arrives.

#### Scenario: Reopen with a pinned theme
- **GIVEN** Theme is Light, the mirror holds `light` and the OS is dark
- **WHEN** the popup opens
- **THEN** its first frame is light

#### Scenario: Mirror lost
- **GIVEN** Theme is Dark and the mirror is empty
- **WHEN** the popup opens
- **THEN** it follows the system until the background answers, then is dark
- **AND** the mirror holds `dark` again

### Requirement: Settings tab and Appearance view
The extension SHALL show, in the Settings tab of the unlocked popup, the sections Account security, Autofill, Notifications, Vault, Appearance and About, in that order. Appearance opens its view, titled Appearance, with a back control to the section list. Sections without a view yet are shown disabled with "Available in a later update". The Appearance view holds the Theme control, labelled Theme, showing the stored value.

#### Scenario: Opening Appearance
- **GIVEN** an unlocked account
- **WHEN** the user opens the Settings tab and taps Appearance
- **THEN** the Appearance view shows Theme with its stored value
- **AND** the back control returns to the section list

#### Scenario: Section not built yet
- **WHEN** the section list renders
- **THEN** Account security, Autofill, Notifications, Vault and About are disabled and explain "Available in a later update"

#### Scenario: Section covers the tab bar
- **GIVEN** the Appearance view is open
- **THEN** the tab bar is not shown
- **AND** back returns to the section list with the tab bar

#### Scenario: Background does not answer
- **GIVEN** the background does not reply to `settings.set`
- **WHEN** the user selects Dark
- **THEN** the control returns to the stored value, the theme is unchanged, and an error says Keepiq is not responding

### Requirement: Settings messages
The background SHALL answer `settings.get` with the global settings record and `settings.set` with a partial patch, validating it, storing it and replying with the full record. A patch value that fails validation is ignored. `settings.get` does not count as user activity for the vault timeout; `settings.set` does. Both work whether or not an account is unlocked.

#### Scenario: Invalid patch
- **GIVEN** Theme is Light
- **WHEN** the popup sends `settings.set` with theme `"blue"`
- **THEN** the stored record is unchanged and the reply holds Light

#### Scenario: Locked vault
- **GIVEN** the active account is locked
- **WHEN** the popup sends `settings.get`
- **THEN** the reply holds the stored theme
