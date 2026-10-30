## MODIFIED Requirements

### Requirement: One-time codes
When a matched login holds a seed in its own `totp` field, or the site has a TOTP item, the apps MUST make its current code available. On Android the service fills a detected one-time-code field. On iOS 18 the extension supplies the code as a one-time-code credential. On iOS 17 it copies the code to the clipboard after the password fill, expiring after 60 seconds.

#### Scenario: Code after the password on Android
- **GIVEN** a login with a TOTP item and a two-step sign-in form
- **WHEN** the one-time-code field appears
- **THEN** Keepiq offers the current code and fills it

#### Scenario: Code from the login's own field on Android
- **GIVEN** a login for `example.com` with a seed in its `totp` field, and no TOTP item
- **WHEN** the one-time-code field of `example.com` appears
- **THEN** Keepiq offers the login's current code and fills it

#### Scenario: Code on the clipboard on iOS 17
- **GIVEN** a login with a TOTP item on iOS 17
- **WHEN** the user fills the password
- **THEN** the current code is on the clipboard, and it is cleared after 60 seconds
