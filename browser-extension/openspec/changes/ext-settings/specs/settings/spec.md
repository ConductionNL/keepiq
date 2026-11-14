## ADDED Requirements

### Requirement: Every Settings section opens
The extension SHALL open Account security, Autofill, Notifications, Vault, Appearance and About from the section list ext-theme added, each as its own view with a back control, so no section shows "Available in a later update". The tab holds no state of its own; every view renders from the background's reply.

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

### Requirement: Account settings storage and live effect
The extension SHALL store every account-scoped setting in one record per account in `storage.local` under `settings.<accountId>`, and Appearance in ext-theme's global `settings.global`. Each key has a default and a validator, and a stored value that fails validation is read as its default. A write MUST patch the record and keep every key it does not name. A change takes effect on the next read without restarting the extension. The record is kept when a 401 or the timeout action logs the account out, and deleted on account removal, which Log out does.

#### Scenario: Fresh profile
- **GIVEN** no settings record exists for an account
- **WHEN** the popup reads the account's settings
- **THEN** every setting reports its default

#### Scenario: Invalid stored value
- **GIVEN** a stored vault timeout of `"tomorrow"`
- **WHEN** the background reads the timeout
- **THEN** it uses the default of 15 minutes

#### Scenario: A write keeps the other keys
- **GIVEN** a stored clipboard delay of 20 seconds
- **WHEN** the user changes the vault timeout
- **THEN** the clipboard delay is still 20 seconds

#### Scenario: Change without restart
- **GIVEN** the clipboard clear delay is Never
- **WHEN** the user sets it to 30 seconds and copies a password
- **THEN** the copy uses the 30 second delay

#### Scenario: Account removed
- **GIVEN** an account with a settings record and a PIN
- **WHEN** the account is removed
- **THEN** its settings record and both PIN copies are deleted
- **AND** the global Appearance record is unchanged

### Requirement: Organisation idle maximum
The extension SHALL read the organisation's idle lock maximum from `GET /api/v1/extension/policy` (`maxIdleMinutes`, ADR-003) on every sync and after every login, and store it per account in `storage.local`. Any positive whole number of minutes is accepted; a failed request or a `maxIdleMinutes` that is not one keeps the stored value. The stored value applies while offline. It is kept when a 401 or the timeout action logs the account out, so a new login is never unclamped, and deleted on account removal. Until a value has been read, the user's timeout applies unclamped. No `KEEPIQ_FEATURES` gate applies: the route is older than `MIN_KEEPIQ_VERSION`.

#### Scenario: Read on sync
- **GIVEN** the server answers `{ "maxIdleMinutes": 30 }`
- **WHEN** a sync runs
- **THEN** the account's stored maximum is 30 minutes

#### Scenario: Offline
- **GIVEN** a stored maximum of 30 minutes and the server is unreachable
- **WHEN** a sync fails
- **THEN** the maximum stays 30 minutes and keeps clamping the timeout

#### Scenario: Unusable reply
- **GIVEN** a stored maximum of 30 minutes
- **WHEN** the server answers `{ "maxIdleMinutes": 0 }` or `{ "maxIdleMinutes": "abc" }`
- **THEN** the stored maximum stays 30 minutes

#### Scenario: A maximum Keepiq may add later
- **WHEN** the server answers `{ "maxIdleMinutes": 120 }`
- **THEN** the stored maximum is 120 minutes

#### Scenario: Never read
- **GIVEN** an account whose maximum was never read and a stored timeout of Never
- **WHEN** the Account security view renders
- **THEN** Never is shown as chosen, without a note

#### Scenario: Logged out by a 401
- **GIVEN** a stored maximum of 30 minutes
- **WHEN** a 401 logs the account out and the user logs in again
- **THEN** the maximum is still 30 minutes before the next sync

#### Scenario: Account removed
- **GIVEN** a stored maximum
- **WHEN** the account is removed
- **THEN** the stored maximum is deleted

### Requirement: Vault timeout and timeout action
The extension SHALL offer the vault timeout options Immediately, 1 minute, 5 minutes, 15 minutes, 30 minutes, 1 hour, 4 hours, On system lock, On browser restart, Never and Custom (hours and minutes), default 15 minutes, and the timeout actions Lock and Log out, default Lock, stored per account. Choosing Never MUST show the ADR-002 warning and require confirmation. Choosing Log out MUST show a note that returning needs the app password again. Durations in notes and messages read like "2 h 30 min".

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

### Requirement: Lock now and Log out
The extension SHALL show Lock now and Log out as two separate actions labelled exactly so, per ADR-002. Lock now locks the active account immediately. Log out asks for confirmation, as everywhere else in the popup, then removes the active account.

#### Scenario: Lock now
- **GIVEN** an unlocked account with a PIN
- **WHEN** the user taps Lock now
- **THEN** the private key is purged per ADR-002, the unlock screen is shown, and it offers the PIN

#### Scenario: Log out cancelled
- **GIVEN** an unlocked account
- **WHEN** the user taps Log out and dismisses the confirmation
- **THEN** nothing changes and the view stays on Account security

### Requirement: Unlock with PIN
The extension SHALL offer an "Unlock with PIN" toggle per account. Turning it on asks for the master password and a PIN of at least six characters of any kind, entered twice; the PIN is set only when the master password opens the account's cached vault key. The dialog advises against reusing a phone or bank PIN. Settings and the dialog say "After a browser restart you enter your master password once; then your PIN works again."

A PIN is kept in two copies (ADR-002):
- the session copy: the private key wrapped under a key derived from the PIN, with its failed-attempt count, in `storage.session`, or in the background page's memory where `storage.session` is missing. It MUST never be written to `storage.local`.
- the seal: the PIN in `storage.local` under `pinSeal.<accountId>`, encrypted and authenticated with a key derived from the private key and bound to the account, suite and unlock-key epoch. Only a key derived from the account's own private key may open it.

While the session copy exists, the unlock screen offers a PIN field first and a "Use master password" link, and each wrong PIN says how many attempts are left. While only the seal exists, after a browser restart or extension reload, the unlock screen shows the master password field with "After a browser restart you enter your master password once; then your PIN works again." and no PIN field. A master-password unlock that finds the seal without the session copy rebuilds the session copy after the unlock completes. A seal that fails to open or names another suite or epoch is deleted, and the PIN shows as off.

The PIN, both copies, is forgotten on the fifth wrong PIN in a row, when the user turns the toggle off, on logout (by hand, by the timeout action or by a revoked app password), on account removal, and when a sync finds a new suite or unlock-key epoch or the two-factor policy withholds the key. A browser restart or extension reload clears only the session copy. A lock, by hand or by the timeout, forgets neither. Attempts on one account run one at a time, and each is counted before the PIN is checked. There is no unlock by PIN alone after a restart.

#### Scenario: Setting a PIN
- **GIVEN** an unlocked account without a PIN
- **WHEN** the user turns on Unlock with PIN, enters the master password and `<pin>` twice
- **THEN** the wrapped key is in `storage.session` and `pinSeal.<accountId>` is in `storage.local`
- **AND** `storage.local` holds neither the PIN nor the private key in a form the PIN alone opens
- **AND** the toggle shows as on
- **AND** the dialog advised against reusing a phone or bank PIN and said the master password is needed once after a browser restart

#### Scenario: Wrong master password
- **GIVEN** the user is turning on Unlock with PIN
- **WHEN** they enter a wrong master password
- **THEN** the dialog says "Invalid master password" and no PIN is set

#### Scenario: PIN too short
- **GIVEN** the user is turning on Unlock with PIN
- **WHEN** they enter five characters
- **THEN** the dialog refuses with "Use at least 6 characters"

#### Scenario: Master password left empty
- **GIVEN** the user is turning on Unlock with PIN
- **WHEN** they leave the master password empty
- **THEN** the dialog refuses with "Enter your master password" and sends nothing

#### Scenario: Unlocking with the PIN
- **GIVEN** an account locked by Lock now or the timeout, with a PIN
- **WHEN** the user enters the correct PIN
- **THEN** the private key is restored per ADR-002 and the vault opens
- **AND** the unlock sends no request while the stored Keepiq version is supported

#### Scenario: Wrong PIN
- **GIVEN** a locked account with a PIN
- **WHEN** the user enters a wrong PIN
- **THEN** the unlock screen says "Wrong PIN, 4 attempts left"

#### Scenario: Five wrong PINs
- **GIVEN** a locked account with a PIN and four failed attempts
- **WHEN** the fifth attempt is wrong
- **THEN** the session copy and the seal are deleted
- **AND** the unlock screen shows the master password field with "PIN forgotten after too many attempts"

#### Scenario: Five wrong PINs, then a restart
- **GIVEN** a PIN forgotten after five wrong attempts
- **WHEN** the browser restarts and the user unlocks with the master password
- **THEN** no PIN is rebuilt and Unlock with PIN shows as off

#### Scenario: Master password changed elsewhere
- **GIVEN** an account with a PIN
- **WHEN** a sync finds a new unlock-key epoch
- **THEN** the PIN is forgotten and the unlock screen asks for the master password

#### Scenario: PIN made for another vault key
- **GIVEN** a PIN whose record names a suite or epoch other than the cached one
- **WHEN** the user enters the PIN
- **THEN** the PIN is forgotten and the unlock screen says "Your vault key changed. Unlock with your master password."

#### Scenario: Browser restart
- **GIVEN** an account with a PIN
- **WHEN** the browser restarts
- **THEN** the unlock screen shows the master password field with "After a browser restart you enter your master password once; then your PIN works again." and no PIN field

#### Scenario: Master password once after a restart, then the PIN
- **GIVEN** an account with a PIN and a browser that restarted
- **WHEN** the user unlocks with the master password, then locks
- **THEN** the unlock reply does not wait for the PIN to be rebuilt
- **AND** the next unlock screen offers the PIN, and the same PIN opens the vault

#### Scenario: A forged seal
- **GIVEN** a browser that restarted, and a `pinSeal.<accountId>` written by someone who does not hold the private key, such as one encrypted to the account's public key with RSA-OAEP or a real seal with a changed account, suite or epoch
- **WHEN** the user unlocks with the master password
- **THEN** the seal is deleted, no PIN is rebuilt and Unlock with PIN shows as off

#### Scenario: Another account's seal
- **GIVEN** account A's seal copied to `pinSeal.<B>`
- **WHEN** account B unlocks with the master password after a restart
- **THEN** the seal is deleted and B has no PIN

#### Scenario: Every forget clears both copies
- **GIVEN** an account with both PIN copies
- **WHEN** the fifth wrong PIN, turning the toggle off, a logout, an account removal, a 401, a new suite or epoch, or the two-factor block happens
- **THEN** neither `pin.<accountId>` in `storage.session` nor `pinSeal.<accountId>` in `storage.local` remains

#### Scenario: A forget during the rebuild
- **GIVEN** a master-password unlock after a restart that is rebuilding the session copy from the seal
- **WHEN** the account logs out, or a sync finds a new epoch, before the rebuild stores its result
- **THEN** neither PIN copy remains afterwards

#### Scenario: Firefox without storage.session
- **GIVEN** Firefox below 115
- **WHEN** the user sets a PIN
- **THEN** the wrapped key is held in the background page's memory only, and after a restart the master password brings the PIN back

### Requirement: Biometrics and master password entries
The extension SHALL show "Unlock with biometrics" as a disabled toggle with the text "Not available yet", and "Change master password" as a link that opens the active account's Keepiq web app in a new tab. Neither entry stores anything.

#### Scenario: Biometrics entry
- **WHEN** the Account security view renders
- **THEN** the biometrics toggle is off, disabled and explained with "Not available yet"

#### Scenario: Change master password on a subfolder install
- **GIVEN** the active account's server is `https://example.org/cloud`
- **WHEN** the user taps Change master password
- **THEN** a new tab opens at `https://example.org/cloud/index.php/apps/keepiq/`

### Requirement: Autofill settings entries
The extension SHALL store per account the entries Autofill on page load (default off, with the warning "Compromised or untrusted websites can exploit autofill on page load"), Default autofill setting for login items (Autofill on page load if enabled, default, or Never), Default URI match detection (Base domain, default; Host; Starts with; Exact; Regular expression; Never), Show autofill suggestions on form fields (default on) and Enable context menu options (default on). Each change is stored immediately and read by ext-autofill on its next use. Show identities as suggestions and Show cards as suggestions are not shown.

ext-autofill delivers the behaviour behind these entries and behind Ask to add login, Ask to update existing login and Excluded domains. Until the background reports an entry's behaviour as delivered, the entry is shown disabled with "Coming later" and its stored value and default are left untouched.

#### Scenario: Entries not yet delivered
- **GIVEN** the background reports no autofill behaviour as delivered and a stored Enable context menu options of off
- **WHEN** the Autofill view renders
- **THEN** every entry above is disabled with "Coming later"
- **AND** Enable context menu options still shows off and stays stored as off

#### Scenario: Enabling autofill on page load
- **GIVEN** fill on page load is delivered and Autofill on page load is off
- **WHEN** the user turns it on
- **THEN** the warning text is shown beneath the toggle and the Default autofill setting entry becomes enabled

#### Scenario: Regular expression match chosen
- **GIVEN** URI matching is delivered
- **WHEN** the user selects Regular expression as the default URI match
- **THEN** the note "Regular expressions are an advanced option and can break matching" is shown and the value is stored

### Requirement: Autofill keyboard shortcut display
The extension SHALL show the shortcut of the `autofill_login` command (declared by ext-autofill), read with `browser.commands.getAll()`, with a Change control. On Chromium, Change opens `chrome://extensions/shortcuts` in a new tab. On Firefox, where an extension cannot open privileged pages, Change shows the steps to reach Manage Extension Shortcuts from `about:addons`. When that command is missing or has no shortcut, the entry shows "Not set".

#### Scenario: Chromium
- **GIVEN** `autofill_login` is bound to `Ctrl+Shift+L`
- **WHEN** the user taps Change
- **THEN** a new tab opens at `chrome://extensions/shortcuts`

#### Scenario: Firefox
- **WHEN** the user taps Change on Firefox
- **THEN** the view shows "Open about:addons, choose the gear menu, then Manage Extension Shortcuts" with a Copy control for `about:addons`

#### Scenario: No shortcut
- **GIVEN** the manifest declares no `autofill_login`, or the user removed its shortcut
- **WHEN** the Autofill view renders
- **THEN** the shortcut entry shows "Not set"

### Requirement: Clear clipboard
The extension SHALL offer the clipboard clear delay options Never, 10 seconds, 20 seconds, 30 seconds (default), 1 minute, 2 minutes and 5 minutes, stored per account, and the value applies to the next copy made from the extension.

#### Scenario: Delay applied
- **GIVEN** the delay is 20 seconds
- **WHEN** the user copies a password from a vault item
- **THEN** the clipboard is overwritten 20 seconds later

### Requirement: Notification prompts and excluded domains
The extension SHALL store per account the toggles Ask to add login (default on) and Ask to update existing login (default on) and an Excluded domains list (default empty). Each list entry is a base domain, by the same public-suffix-aware rule ext-autofill matches and excludes with (`baseDomain` in `src/vault/match.ts`). The Excluded domains view offers Add current site, which adds the current tab's base domain, a free-text field, and a remove control per entry. Free text is lowercased, and a host or pasted URL is reduced to its base domain. Duplicate, empty and unreadable entries are refused. These entries follow the "Coming later" rule of the Autofill settings until ext-autofill delivers the save prompts.

#### Scenario: Add current site
- **GIVEN** the save prompts are delivered and the current tab is `https://login.example.co.uk/signin`
- **WHEN** the user taps Add current site
- **THEN** `example.co.uk` is appended to the list and stored

#### Scenario: Free text
- **GIVEN** the save prompts are delivered
- **WHEN** the user enters `HTTPS://Accounts.Example.test/path`
- **THEN** `example.test` is appended to the list

#### Scenario: Duplicate domain
- **GIVEN** the save prompts are delivered and `example.test` is already listed
- **WHEN** the user adds `www.example.test`
- **THEN** the list is unchanged and "Already excluded" is shown

#### Scenario: Current site unavailable
- **GIVEN** the save prompts are delivered and the current tab is a browser page without a host
- **WHEN** the Excluded domains view renders
- **THEN** Add current site is disabled

### Requirement: Keepiq on the web
The Notifications view SHALL end with a "Keepiq on the web" group saying that Keepiq's own notifications, import and export, and the master password are managed in Keepiq on Nextcloud, under Personal settings, with a link that opens the active account's Keepiq web app in a new tab. The extension reads and writes none of Keepiq's user preferences.

#### Scenario: Open Keepiq
- **GIVEN** the active account's server is `https://example.org/cloud`
- **WHEN** the user taps Open Keepiq in the Keepiq on the web group
- **THEN** a new tab opens at `https://example.org/cloud/index.php/apps/keepiq/`
- **AND** no request goes to `/api/settings/user`

### Requirement: Vault section entries
The extension SHALL show Sync now with "Last sync: <time>" (or "Never" before the first sync), Folders (the folder manager from ext-vault-edit), Import items and Export vault (each opens the Keepiq web app in a new tab) and Default item type. Export vault is disabled with "Turned off by your organisation" while the stored policy reports `vault_export_disabled` for the account. Default item type is labelled "Default item type in this extension", is stored per account, lists the account's cached secret types by label except `passkey`, defaults to Login, and is the type New starts with. A stored type the account's cached types lack is read as Login. There is no website-icons setting (ADR-002).

#### Scenario: Sync now
- **GIVEN** an unlocked account online
- **WHEN** the user taps Sync now
- **THEN** a sync runs and "Last sync" updates on completion

#### Scenario: Sync now offline
- **WHEN** the user taps Sync now while the server is unreachable
- **THEN** "Sync failed, you are offline" is shown and "Last sync" keeps the previous time

#### Scenario: Sync now server error
- **WHEN** the user taps Sync now and the server answers with an error or is busy
- **THEN** "Sync failed, try again later" is shown and "Last sync" keeps the previous time

#### Scenario: Export turned off
- **GIVEN** the last policy read reports `vault_export_disabled: true`
- **WHEN** the Vault view renders
- **THEN** Export vault is disabled with "Turned off by your organisation" and Import items stays a link

#### Scenario: Default item type
- **GIVEN** the user picks Note as the default item type
- **WHEN** they tap New in the Vault tab
- **THEN** the form starts as a note

#### Scenario: Default type missing on this server
- **GIVEN** a stored default item type `<slug>` that the account's cached types do not contain
- **WHEN** the Vault view renders
- **THEN** the control shows Login and New starts as a login

### Requirement: Appearance settings
The extension SHALL add to ext-theme's Appearance view, below Theme, and store in `settings.global` the settings Compact mode (default off), Show animations (default on) and Show quick copy actions on vault (default on), and SHALL show Language read-only as the browser UI language.

#### Scenario: Compact mode
- **GIVEN** Compact mode is off
- **WHEN** the user turns it on
- **THEN** the popup tightens its spacing without reopening

#### Scenario: Show animations off
- **GIVEN** Show animations is on
- **WHEN** the user turns it off
- **THEN** the popup's transitions and animations stop, except the loading spinner, which keeps turning slowly as it does under reduced motion

#### Scenario: Show quick copy actions off
- **GIVEN** a vault login with a username and a password
- **WHEN** the user turns Show quick copy actions on vault off
- **THEN** its card in the Vault tab offers no Copy username, Copy password or Copy verification code
- **AND** the item detail still offers Copy

#### Scenario: Language entry
- **WHEN** the Appearance view renders
- **THEN** Language shows the browser UI language and "Follows your browser" and has no control

### Requirement: About panel
The extension SHALL show the extension version from `browser.runtime.getManifest().version`, the active account's server address and that server's Keepiq version as the background last stored it, and the links Help, Report a bug, Contact support, Privacy policy, Keepiq web app and Rate the extension, at the addresses in design.md. The popup shows the version as received and never parses it. Rate the extension points at the store for the running browser and is hidden while that store's address is `null`.

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
