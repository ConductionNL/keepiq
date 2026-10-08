## ADDED Requirements

### Requirement: Settings tab with six sections
The extension SHALL show a Settings tab in the unlocked popup with the sections Account security, Autofill, Notifications, Vault, Appearance and About, in that order, each opening as its own view with a back control. The tab holds no state of its own; every view renders from the background's reply to `settings.get`.

#### Scenario: Opening a section
- **GIVEN** an unlocked account
- **WHEN** the user opens the Settings tab and taps Autofill
- **THEN** the Autofill view renders with every entry showing its stored value
- **AND** the back control returns to the section list

#### Scenario: Locked vault
- **GIVEN** the active account is locked
- **WHEN** the popup opens
- **THEN** the unlock screen is shown and the Settings tab is not reachable
- **AND** the Appearance settings still apply to the unlock screen

### Requirement: Settings schema, storage and live effect
The extension SHALL define every setting in one schema with a typed key, a default, a validator and a scope, and MUST store account-scoped settings as one record per account in `storage.local` under the account id and global-scoped settings (Appearance) in one shared `storage.local` record. Account records are cleared on account removal; the global record is never cleared automatically. A stored value that fails validation is read as its default. A change takes effect on the next read without restarting the extension, and the background re-reads the timeout, action and clipboard values each time it needs them.

#### Scenario: Fresh profile
- **GIVEN** no settings record exists for an account
- **WHEN** the popup requests settings
- **THEN** every setting reports its schema default

#### Scenario: Invalid stored value
- **GIVEN** a stored vault timeout of `"tomorrow"`
- **WHEN** the background reads the timeout
- **THEN** it uses the default of 15 minutes
- **AND** the next write replaces the invalid value

#### Scenario: Change without restart
- **GIVEN** the clipboard clear delay is Never
- **WHEN** the user sets it to 30 seconds and copies a password
- **THEN** the copy uses the 30 second delay

#### Scenario: Account removed
- **GIVEN** an account with a settings record and a PIN-wrapped key
- **WHEN** the account is removed
- **THEN** its settings record and PIN blob are deleted from `storage.local` and `storage.session`
- **AND** the global Appearance record is unchanged

### Requirement: Vault timeout and timeout action
The extension SHALL offer the vault timeout options Immediately, 1 minute, 5 minutes, 15 minutes, 30 minutes, 1 hour, 4 hours, On browser restart, Never and Custom (hours and minutes), default 15 minutes, and the timeout actions Lock and Log out, default Lock, stored per account in `storage.local`. Choosing Never MUST show the ADR-002 warning and require confirmation. Choosing Log out MUST show a note that returning needs the app password again. When the policy hook reports a maximum timeout or a forced action, options above the maximum are hidden, a stored value above it is clamped on read, and the forced action is shown disabled with "Set by your organisation".

#### Scenario: Choosing Never
- **GIVEN** the timeout is On browser restart
- **WHEN** the user selects Never
- **THEN** a warning states that the vault stays unlocked until locked by hand and that the key that opens it is written to this browser profile's disk, readable by anyone who can read the profile
- **AND** the value is stored only after the user confirms

#### Scenario: Custom timeout
- **GIVEN** the user selects Custom
- **WHEN** they enter 2 hours and 30 minutes
- **THEN** the timeout is stored as 150 minutes and displayed as "2 h 30 min"
- **AND** an entry of 0 hours and 0 minutes is rejected with "Enter at least one minute"

#### Scenario: Policy clamp
- **GIVEN** the policy hook reports a maximum of 30 minutes and a forced action of Lock
- **WHEN** the Account security view renders
- **THEN** 1 hour, 4 hours, On browser restart and Never are not offered
- **AND** the action control shows Lock, disabled, with "Set by your organisation"
- **AND** a previously stored 4 hours is read as 30 minutes

### Requirement: Lock now and Log out
The extension SHALL show Lock now and Log out as two separate actions labelled exactly so, per ADR-002. Lock now locks the active account immediately. Log out asks for confirmation and states that the app password will be needed again, then logs out the active account.

#### Scenario: Lock now
- **GIVEN** an unlocked account
- **WHEN** the user taps Lock now
- **THEN** the private key and any PIN-wrapped key are purged per ADR-002 and the unlock screen is shown

#### Scenario: Log out cancelled
- **GIVEN** an unlocked account
- **WHEN** the user taps Log out and dismisses the confirmation
- **THEN** nothing changes and the view stays on Account security

### Requirement: Unlock with PIN
The extension SHALL offer an "Unlock with PIN" toggle that, when enabled, asks for a PIN of at least four characters and shows a "Require master password on browser restart" toggle, default on. The PIN-wrapped private key MUST be stored in `storage.session` when that toggle is on and in `storage.local` when it is off, together with a failed-attempt counter, and MUST never be stored anywhere else. The blob is cleared by disabling the toggle, by Lock now, by timeout, by logout, by account removal, by five consecutive wrong PINs, and by a suite change on sync. The unlock screen shows a PIN field with a "Use master password" link while a blob exists for the account.

#### Scenario: Enabling PIN
- **GIVEN** an unlocked account without a PIN
- **WHEN** the user enables Unlock with PIN, enters `<pin>` twice and leaves "Require master password on browser restart" on
- **THEN** the wrapped key is written to `storage.session`
- **AND** the toggle shows as on

#### Scenario: Enabling PIN that survives restart
- **GIVEN** the user turns "Require master password on browser restart" off while enabling a PIN
- **WHEN** they confirm
- **THEN** a warning states that the key protected by the PIN alone is written to this browser profile's disk
- **AND** the wrapped key is written to `storage.local` only after confirmation

#### Scenario: Unlocking with the PIN
- **GIVEN** a locked account with a PIN blob
- **WHEN** the user enters the correct PIN
- **THEN** the private key is restored per ADR-002 without a server round trip and the vault opens

#### Scenario: Five wrong PINs
- **GIVEN** a locked account with a PIN blob and four failed attempts
- **WHEN** the fifth attempt is wrong
- **THEN** the PIN blob and counter are deleted
- **AND** the unlock screen switches to the master password field with "PIN disabled after too many attempts"
- **AND** the Unlock with PIN toggle shows as off after the next unlock

#### Scenario: PIN too short
- **GIVEN** the user is enabling a PIN
- **WHEN** they enter three characters
- **THEN** the dialog refuses with "Use at least 4 characters"

#### Scenario: Firefox without storage.session
- **GIVEN** Firefox below 115 and "Require master password on browser restart" on
- **WHEN** the user enables a PIN
- **THEN** the wrapped key is held in the background page's memory only and the PIN works until the browser restarts

### Requirement: Biometrics and master password entries
The extension SHALL show "Unlock with biometrics" as a disabled toggle with the text "Not available yet" and SHALL show "Change master password" as a link that opens the Keepiq web app at the active account's server in a new tab. Neither entry stores anything.

#### Scenario: Biometrics entry
- **WHEN** the Account security view renders
- **THEN** the biometrics toggle is off, disabled and explained with "Not available yet"

#### Scenario: Change master password
- **GIVEN** the active account's server is `https://<host>`
- **WHEN** the user taps Change master password
- **THEN** a new tab opens at `https://<host>/index.php/apps/keepiq/`

### Requirement: Autofill settings entries
The extension SHALL store per account in `storage.local` the entries Autofill on page load (default off, with the warning "Compromised or untrusted websites can exploit autofill on page load"), Default autofill setting for login items (Autofill on page load if enabled, default, or Never), Default URI match detection (Base domain, default; Host; Starts with; Exact; Regular expression; Never), Show autofill suggestions on form fields (default on) and Enable context menu options (default on). Each change is stored immediately and read by ext-autofill on its next use. Show identities as suggestions and Show cards as suggestions are not shown.

#### Scenario: Enabling autofill on page load
- **GIVEN** Autofill on page load is off
- **WHEN** the user turns it on
- **THEN** the warning text is shown beneath the toggle and the Default autofill setting entry becomes enabled

#### Scenario: Inline suggestions not yet delivered
- **GIVEN** the background reports that inline suggestions are unsupported
- **WHEN** the Autofill view renders
- **THEN** Show autofill suggestions on form fields is disabled with "Coming later" and its stored value is left untouched

#### Scenario: Regular expression match chosen
- **WHEN** the user selects Regular expression as the default URI match
- **THEN** the note "Regular expressions are an advanced option and can break matching" is shown and the value is stored

### Requirement: Autofill keyboard shortcut display
The extension SHALL show the current autofill shortcut read from `browser.commands.getAll()` with a Change control. On Chromium, Change opens `chrome://extensions/shortcuts` in a new tab. On Firefox, where privileged pages cannot be opened by an extension, Change shows the steps to reach Manage Extension Shortcuts from `about:addons`. When no command is declared yet, the entry shows "Not set".

#### Scenario: Chromium
- **GIVEN** the command is bound to `Ctrl+Shift+L`
- **WHEN** the user taps Change
- **THEN** a new tab opens at `chrome://extensions/shortcuts`

#### Scenario: Firefox
- **WHEN** the user taps Change on Firefox
- **THEN** the view shows "Open about:addons, choose the gear menu, then Manage Extension Shortcuts" with a Copy control for `about:addons`

### Requirement: Clear clipboard
The extension SHALL offer the clipboard clear delay options Never (default), 10 seconds, 20 seconds, 30 seconds, 1 minute, 2 minutes and 5 minutes, stored per account in `storage.local`, and the value applies to the next copy made from the extension.

#### Scenario: Delay applied
- **GIVEN** the delay is 20 seconds
- **WHEN** the user copies a password from a vault item
- **THEN** the clipboard is cleared 20 seconds later if it still holds that value

### Requirement: Notification prompts and excluded domains
The extension SHALL store per account in `storage.local` the toggles Ask to add login (default on) and Ask to update existing login (default on) and an Excluded domains list (default empty). The Excluded domains view offers Add current site, which adds the active tab's host, a free-text field that accepts a hostname, and a remove control per entry. Duplicate and empty entries are rejected.

#### Scenario: Add current site
- **GIVEN** the active tab is `https://example.test/login`
- **WHEN** the user taps Add current site
- **THEN** `example.test` is appended to the list and stored

#### Scenario: Duplicate host
- **GIVEN** `example.test` is already listed
- **WHEN** the user adds `example.test` again
- **THEN** the list is unchanged and "Already excluded" is shown

#### Scenario: Current site unavailable
- **GIVEN** the active tab is a browser page without a host
- **WHEN** the Excluded domains view renders
- **THEN** Add current site is disabled

### Requirement: Keepiq server notifications
The extension SHALL show a "Keepiq server notifications" subsection with the toggles Shares (`notify_shares`), Requests (`notify_requests`), Group shares (`notify_group_shares`) and Security (`notify_security`) read from `GET /api/settings/user` and written with `PUT /api/settings/user` (ADR-003). The last server reply is cached per account in `storage.local` with its fetch time and is re-read on every sync. While offline or after a failed read, the toggles are disabled and a stale indicator shows "Last read <time>". A failed write reverts the toggle and shows the server's message.

#### Scenario: Toggle while online
- **GIVEN** the server reports `notify_shares` as on
- **WHEN** the user turns Shares off
- **THEN** the extension sends `PUT /api/settings/user` with `notify_shares` off and stores the reply as the new cache

#### Scenario: Offline
- **GIVEN** the last read succeeded at `<time>` and the server is unreachable now
- **WHEN** the Notifications view renders
- **THEN** the four toggles show the cached values, disabled, with "Last read <time>, offline"

#### Scenario: Write rejected
- **GIVEN** the server answers `PUT /api/settings/user` with 403
- **WHEN** the user toggles Security
- **THEN** the toggle returns to its previous value and the server's `message` is shown

#### Scenario: Never read
- **GIVEN** no server settings have been fetched for this account
- **WHEN** the view renders offline
- **THEN** the subsection shows "Not loaded yet" and no toggles

### Requirement: Vault section entries
The extension SHALL show Sync now with "Last sync: <time>" (or "Never" before the first sync), Folders (opens the folder manager from ext-vault-edit), Import items and Export vault (each opens the Keepiq web app in a new tab) and Default item type. There is no website-icons setting; icon fetching is ruled out by ADR-002. Default item type mirrors the server's `default_secret_type` with the same cache, stale and write rules as the server notification toggles, offers the cached secret types by label, and falls back to `login` when the server value matches no cached type.

#### Scenario: Sync now
- **GIVEN** an unlocked account online
- **WHEN** the user taps Sync now
- **THEN** the ext-vault-browse sync runs, the server settings are re-read, and "Last sync" updates on completion

#### Scenario: Sync now offline
- **WHEN** the user taps Sync now while the server is unreachable
- **THEN** "Sync failed, you are offline" is shown and "Last sync" keeps the previous time

#### Scenario: Default item type unknown
- **GIVEN** the server reports `default_secret_type` as `<unknown-slug>`
- **WHEN** the Vault view renders
- **THEN** the control shows Login and a note that the server value is not available in this extension

### Requirement: Appearance settings
The extension SHALL store in the global `storage.local` record the settings Theme (System default, Light, Dark; default System default), Compact mode (default off), Show animations (default on) and Show quick copy actions on vault (default on), and SHALL show Language read-only as the browser UI language. A theme change applies to the open popup immediately.

#### Scenario: Theme change
- **GIVEN** Theme is System default and the OS is light
- **WHEN** the user selects Dark
- **THEN** the popup repaints dark without reopening and the unlock screen is dark on the next open

#### Scenario: Language entry
- **WHEN** the Appearance view renders
- **THEN** Language shows the browser UI language and "Follows your browser" and has no control

### Requirement: About panel
The extension SHALL show the extension version from `browser.runtime.getManifest().version`, the active account's server origin, and the links Help, Report a bug, Privacy policy, Keepiq web app and Rate the extension. Because Keepiq registers no capabilities entry, no Keepiq app version is shown. Rate the extension points at the store for the running browser and is hidden while that store URL is a placeholder.

#### Scenario: About renders
- **GIVEN** the manifest version is `0.0.1` and the active server is `https://<host>`
- **WHEN** the About view renders
- **THEN** it shows "Version 0.0.1" and "Server https://<host>"

#### Scenario: Store link unpublished
- **GIVEN** the store URL for the running browser is still a placeholder
- **WHEN** the About view renders
- **THEN** Rate the extension is not shown
