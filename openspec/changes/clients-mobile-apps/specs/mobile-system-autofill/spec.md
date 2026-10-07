## ADDED Requirements

### Requirement: Keepiq as the system password provider on iOS
The iOS app MUST ship an AutoFill credential provider extension. Once the user selects Keepiq under Settings, General, AutoFill & Passwords, it offers matching logins in apps and in Safari and other browsers. It keeps `ASCredentialIdentityStore` up to date with service identifiers and user names, and never with secret values.

#### Scenario: Fill a login in an app
- **GIVEN** Keepiq selected as the AutoFill provider and a stored login for `example.com`
- **WHEN** the user taps the password field of an app associated with `example.com`
- **THEN** the QuickType bar offers that login, and choosing it fills the user name and password after unlock

#### Scenario: The vault is locked
- **GIVEN** a locked vault
- **WHEN** the user picks a Keepiq suggestion
- **THEN** the extension asks for Face ID, Touch ID, the PIN or the master password, then fills

#### Scenario: Pick another login
- **GIVEN** several logins for one site
- **WHEN** the user opens the Keepiq list from the AutoFill sheet
- **THEN** they can search the vault and fill any login

### Requirement: Keepiq as the autofill service on Android
The Android app MUST provide an `AutofillService`. After the user chooses Keepiq as the autofill service, it detects user name, password and one-time-code fields and offers matching logins in apps and supported browsers: inline in the keyboard on Android 11+, as a dropdown otherwise. It MUST match an app by package name and signing certificate, and a website by the domain the browser reports.

#### Scenario: Fill a login in Chrome
- **GIVEN** Keepiq as the autofill service and a stored login for `example.com`
- **WHEN** the user focuses the password field on `https://example.com/login` in Chrome
- **THEN** Keepiq offers that login and fills it after unlock

#### Scenario: A look-alike app gets nothing
- **GIVEN** a login linked to the app `com.example.bank`
- **WHEN** another app with the same package name but a different signing certificate asks for autofill
- **THEN** Keepiq offers nothing for it

#### Scenario: Locked dataset
- **GIVEN** a locked vault
- **WHEN** a login form asks for autofill
- **THEN** Keepiq shows one "Unlock Keepiq" entry without account names, and fills after the user authenticates

### Requirement: One-time codes
When a matched login has a TOTP item, the apps MUST make its current code available. On Android the service fills a detected one-time-code field. On iOS 18 the extension supplies the code as a one-time-code credential. On iOS 17 it copies the code to the clipboard after the password fill, expiring after 60 seconds.

#### Scenario: Code after the password on Android
- **GIVEN** a login with a TOTP item and a two-step sign-in form
- **WHEN** the one-time-code field appears
- **THEN** Keepiq offers the current code and fills it

#### Scenario: Code on the clipboard on iOS 17
- **GIVEN** a login with a TOTP item on iOS 17
- **WHEN** the user fills the password
- **THEN** the current code is on the clipboard, and it is cleared after 60 seconds

### Requirement: Save new logins
On Android the service MUST offer to save a login the user typed in, or to update a stored one whose password changed, honouring the same never-save list as the browser extension. iOS gives a third-party provider no such hook, so the iOS app MUST offer "Add login" in the AutoFill list and in the app.

#### Scenario: Save after signing up on Android
- **GIVEN** a sign-up form filled by hand
- **WHEN** the user submits it
- **THEN** Keepiq asks to save the login, and saving creates an item for that app or domain

#### Scenario: Never save for this site
- **GIVEN** a domain on the never-save list
- **WHEN** the user submits a login there
- **THEN** Keepiq does not ask

### Requirement: Autofill index follows the vault
The autofill index MUST be rebuilt after every sync and cleared on unpair, on a suite change and when the user turns autofill off. An item deleted or moved out of reach on the server MUST stop being offered after the next sync.

#### Scenario: A deleted login disappears
- **GIVEN** a login deleted in the web app
- **WHEN** the phone syncs
- **THEN** autofill no longer offers it
