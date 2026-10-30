## MODIFIED Requirements

### Requirement: Pair a phone with Login Flow v2
The apps MUST connect to a Nextcloud account through Nextcloud Login Flow v2. The user types the server address, signs in on the server's own login page in the system browser, and the app receives an app password. Then it calls `POST /api/v1/extension/pair`. Manual entry of an app password MUST remain as a fallback. Up to 5 accounts can be paired on one device. The pair response MUST list the `totp-field` and `passkeys-field` capabilities; the apps MUST NOT pair a server that lacks either. An account paired before this check MUST be checked again, through the same pair call, before the app writes a login's `totp` or `passkeys`; when a capability is missing, the write MUST be refused before anything is sent, with the message pairing gives, so no code or passkey is sent to a server that would drop it.

#### Scenario: Sign in through the browser
- **GIVEN** a reachable https server
- **WHEN** the user enters its address and signs in on the login page, including two-factor
- **THEN** the app receives an app password, the pair call succeeds and the account appears in the app

#### Scenario: The user abandons the login
- **GIVEN** a started login flow
- **WHEN** the user closes the browser tab, or 20 minutes pass
- **THEN** the app stops polling and shows the address form again, with nothing stored

#### Scenario: Unsupported server
- **GIVEN** a server whose pair response reports an `apiVersion` the app does not support, or without keepiq installed
- **WHEN** the user pairs
- **THEN** the app explains what is missing and stores no account

#### Scenario: Server without a login's code and passkeys
- **GIVEN** a server whose pair response does not list `totp-field` or `passkeys-field` in its capabilities
- **WHEN** the user pairs
- **THEN** the app asks the user to have Keepiq updated and stores no account

#### Scenario: An account paired earlier on a server without the fields
- **GIVEN** an account paired before the capability check, on a server whose pair response does not list `passkeys-field`
- **WHEN** a site asks the app to create a passkey, or the user removes a login's key or deletes a passkey
- **THEN** the app asks the pair call again, refuses before any key is made or anything is sent, and shows that Keepiq needs updating
