## MODIFIED Requirements

### Requirement: Keepiq as a passkey provider
On iOS 17+ and Android 14+ the apps MUST act as a passkey provider. They offer the passkeys stored in the vault, on a login's `passkeys` field or as a standalone Passkey item, when an app or browser asks to sign in with a passkey, and save new passkeys when one is created. Passkeys use the same credential JSON as the web app and the browser extension, so a passkey created on any client works on the others.

A new passkey MUST be saved onto a login, never as a standalone Passkey item. It goes onto the login for the same site whose user name equals the passkey's user name, ignoring case and surrounding spaces, when exactly one such login exists and the user may edit it. Otherwise the apps MUST create a new login named after the relying party, with its rpId as address, the passkey's user name, an empty password and the passkey. Adding to a login MUST fetch it fresh and keep every passkey it already holds.

A counter written back for a passkey on a login MUST fetch the login fresh and change only that credential's counter.

#### Scenario: Sign in with a passkey made in the browser extension
- **GIVEN** a passkey for `example.com` created with the Keepiq browser extension
- **WHEN** the user signs in to `example.com` on the phone and picks Keepiq in the passkey sheet
- **THEN** the site accepts the signature after the user unlocks

#### Scenario: Sign in with a passkey on a login
- **GIVEN** a login for `example.com` whose `passkeys` field holds a passkey for rpId `example.com`
- **WHEN** the user signs in to `example.com` on the phone and picks that passkey
- **THEN** the site accepts the signature
- **AND** a non-zero counter is stored on that passkey of the login, and its other passkeys are unchanged

#### Scenario: Create a passkey for an account the vault has a login for
- **GIVEN** Keepiq as the passkey provider and one login for `example.com` with user name `alice`
- **WHEN** `example.com` asks to create a passkey for `alice` and the user chooses Keepiq
- **THEN** the passkey is added to that login's `passkeys`, and the web app shows it on the login after its next load
- **AND** no Passkey item is created

#### Scenario: Create a passkey without a matching login
- **GIVEN** Keepiq as the passkey provider and no login for `example.com` with user name `alice`
- **WHEN** `example.com` asks to create a passkey for `alice` and the user chooses Keepiq
- **THEN** a new login for `example.com` with user name `alice`, an empty password and the passkey is saved

#### Scenario: Android before version 14
- **GIVEN** a phone on Android 13
- **WHEN** the user opens the passkey settings in the app
- **THEN** the app explains that passkeys need Android 14, while passwords and codes still fill
