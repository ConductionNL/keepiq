## MODIFIED Requirements

### Requirement: Vault timeout and timeout action
The extension SHALL offer the vault timeout options Immediately, 1 minute, 5 minutes, 15 minutes, 30 minutes, 1 hour, 4 hours, On system lock, On browser restart, Never and Custom (hours and minutes), default 15 minutes, and the timeout actions Lock and Log out, default Lock, stored per account. On a browser without the `idle` API, Safari among them, On system lock is not offered, as in Bitwarden, and the background MUST NOT store it when asked, keeping the rest of that change; the other options run on the extension's own timer and stay. Choosing Never MUST show the ADR-002 warning and require confirmation. Choosing Log out MUST show a note that returning needs the app password again. Durations in notes and messages read like "2 h 30 min".

When an organisation maximum is stored, the effective timeout is the lower of the user's choice and the maximum (ADR-002):
- under any stored maximum, Never and On browser restart are not offered, nor options above the maximum;
- a stored choice above the maximum is read as the maximum and shown with "Set by your organisation", and stays stored, so a higher maximum restores it;
- On system lock locks on system lock or after the maximum idle time, whichever comes first, and says so;
- Immediately also locks after the maximum idle time, so a pop-out window kept open cannot outlast it;
- a Custom entry above the maximum is refused with "Your organisation allows at most <duration>";
- a stored maximum deletes the Never copy of the key from `storage.local` at once.

#### Scenario: Choosing Never
- **GIVEN** no organisation maximum has been read and the timeout is 15 minutes
- **WHEN** the user selects Never
- **THEN** a warning states that the vault stays unlocked until locked by hand and that the key that opens it is written to this browser profile's disk, readable by anyone who can read the profile
- **AND** the value is stored only after the user confirms

#### Scenario: Custom timeout
- **GIVEN** an organisation maximum of 240 minutes
- **WHEN** the user selects Custom and enters 2 hours and 30 minutes
- **THEN** the timeout is stored as 150 minutes and displayed as "2 h 30 min"
- **AND** an entry of 0 hours and 0 minutes is refused with "Enter at least one minute"
- **AND** an entry of 5 hours is refused with "Your organisation allows at most 4 h"
- **AND** an entry of 1.5 hours is refused with "Enter whole hours and minutes"

#### Scenario: Organisation maximum
- **GIVEN** an organisation maximum of 30 minutes and a stored timeout of 4 hours
- **WHEN** the Account security view renders
- **THEN** 1 hour, 4 hours, On browser restart and Never are not offered
- **AND** the timeout shows 30 minutes with "Set by your organisation"
- **AND** the account locks after 30 idle minutes
- **AND** when the maximum later becomes 240 minutes, the timeout is 4 hours again

#### Scenario: On system lock under a maximum
- **GIVEN** an organisation maximum of 60 minutes and the timeout On system lock
- **WHEN** the system stays unlocked and the extension is idle for 60 minutes
- **THEN** the account locks
- **AND** the option reads "On system lock, or after 1 h idle (set by your organisation)"

#### Scenario: A maximum arrives under Never
- **GIVEN** the timeout Never, an unlocked account and its key in `storage.local`
- **WHEN** a sync stores an organisation maximum of 240 minutes
- **THEN** the key is deleted from `storage.local` and the account locks after 240 idle minutes

#### Scenario: Log out action
- **WHEN** the user selects the action Log out
- **THEN** the note "When the timeout ends you are logged out and need the app password again" is shown and the action is stored

#### Scenario: Safari
- **GIVEN** the extension on Safari, which has no `idle` API
- **WHEN** the Account security view renders
- **THEN** On system lock is not offered and every other option is
- **AND** a timeout of 5 minutes still locks after 5 idle minutes
- **AND** a settings change that asks the background for On system lock leaves the stored timeout as it was

### Requirement: Autofill keyboard shortcut display
The extension SHALL show the shortcut of the `autofill_login` command (declared by ext-autofill), read with `browser.commands.getAll()`, with a Change control. On Chromium, Change opens `chrome://extensions/shortcuts` in a new tab. On Firefox, where an extension cannot open privileged pages, Change shows the steps to reach Manage Extension Shortcuts from `about:addons`. On Safari, which changes extension shortcuts in its own settings from Safari 26 and offers no API to open them, Change shows the steps there, without a Copy control. When that command is missing or has no shortcut, the entry shows "Not set".

#### Scenario: Chromium
- **GIVEN** `autofill_login` is bound to `Ctrl+Shift+L`
- **WHEN** the user taps Change
- **THEN** a new tab opens at `chrome://extensions/shortcuts`

#### Scenario: Firefox
- **WHEN** the user taps Change on Firefox
- **THEN** the view shows "Open about:addons, choose the gear menu, then Manage Extension Shortcuts" with a Copy control for `about:addons`

#### Scenario: Safari
- **WHEN** the user taps Change on Safari
- **THEN** the view shows "Open Safari Settings, choose Extensions, then select Keepiq to change its shortcut" (nl "Open de Safari-instellingen, kies Extensies en selecteer Keepiq om de sneltoets te wijzigen") and no Copy control, and no tab opens

#### Scenario: No shortcut
- **GIVEN** the manifest declares no `autofill_login`, or the user removed its shortcut
- **WHEN** the Autofill view renders
- **THEN** the shortcut entry shows "Not set"

### Requirement: Clear clipboard
The extension SHALL offer the clipboard clear delay options Never, 10 seconds, 20 seconds, 30 seconds (default), 1 minute, 2 minutes and 5 minutes, stored per account, and the value applies to the next copy made from the extension. On Chrome and Firefox the clear overwrites the clipboard whatever it holds; on Safari it clears only what Keepiq wrote last (safari-clipboard).

#### Scenario: Delay applied
- **GIVEN** the delay is 20 seconds
- **WHEN** the user copies a password from a vault item
- **THEN** the clipboard is overwritten 20 seconds later

#### Scenario: Safari keeps a later copy
- **GIVEN** Safari and a delay of 20 seconds
- **WHEN** the user copies a password from a vault item, then copies other text before the 20 seconds end
- **THEN** the other text is still on the clipboard after the 20 seconds

### Requirement: About panel
The extension SHALL show the extension version from `browser.runtime.getManifest().version`, the active account's server address and that server's Keepiq version as the background last stored it, and the links Help, Report a bug, Contact support, Privacy policy, Keepiq web app and Rate the extension, at the addresses in design.md. The popup shows the version as received and never parses it. Rate the extension points at the store for the running browser, the Chrome Web Store on Chromium, Firefox Add-ons on Firefox and the Mac App Store on Safari, and is hidden while that store's address is `null`.

#### Scenario: About renders
- **GIVEN** the manifest version is `0.0.1`, the active server is `https://<host>` and its stored Keepiq version is `0.3.12`
- **WHEN** the About view renders
- **THEN** it shows "Version 0.0.1", "Server https://<host>" and "Keepiq 0.3.12"
- **AND** Contact support opens a mail to `support@conduction.nl`, and Report a bug opens the bug-report form of the Keepiq repository

#### Scenario: Keepiq version unknown
- **GIVEN** the account has no stored Keepiq version
- **WHEN** the About view renders
- **THEN** it shows "Keepiq version unknown"

#### Scenario: Store link unpublished
- **GIVEN** the store address for the running browser is `null`
- **WHEN** the About view renders
- **THEN** Rate the extension is not shown

#### Scenario: Safari before the App Store listing
- **GIVEN** the extension on Safari and `rate.safari` `null`
- **WHEN** the About view renders
- **THEN** Rate the extension is not shown, whatever the Chrome and Firefox addresses are
