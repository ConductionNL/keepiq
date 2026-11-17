## ADDED Requirements

### Requirement: Flag logins saved with an unencrypted address

The password health report MUST list, in an Unencrypted address category, every login whose main or extra address starts with `http://`, except addresses on `localhost`, loopback or private-network hosts, and MUST offer a Change to https action that edits the address. The browser extension MUST ask for confirmation before filling a login on a page whose address starts with `http://`.

#### Scenario: A vault user finds a login saved with http

- **GIVEN** a vault user whose login for the intranet is saved as `http://intranet.example.org`
- **WHEN** the user opens the password health report at /password-health
- **THEN** the login is listed under Unencrypted address
- **AND** choosing Change to https saves the address as `https://intranet.example.org`

#### Scenario: The extension warns on an http page

- **GIVEN** an unlocked browser extension on a sign-in page served over `http://`
- **WHEN** the user picks a login to fill
- **THEN** the extension asks for confirmation before it fills

### Requirement: Flag unused two-factor login and available passkeys

When the site directory is switched on, the password health report MUST list, in a Two-factor available category, every login for a site the directory marks as supporting one-time codes when the vault holds no seed for that login, neither on the login nor as an authenticator item matching its host. It MUST list, in a Passkey available category, every login for a site the directory marks as supporting passkeys when the vault holds no passkey item whose relying party id matches the login's registrable domain. The matching MUST run in the browser. When the site directory is switched off, both categories MUST show as unavailable.

#### Scenario: A vault user sees a site that offers one-time codes

- **GIVEN** the site directory is on and lists `github.com` as supporting one-time codes
- **AND** a vault user whose login for `github.com` has no seed and no authenticator item matches it
- **WHEN** the user opens the password health report
- **THEN** the login is listed under Two-factor available

#### Scenario: A passkey already saved

- **GIVEN** the site directory lists `example.com` as supporting passkeys
- **AND** the vault holds a passkey item for relying party `example.com`
- **WHEN** the user opens the password health report
- **THEN** the login for `example.com` is not listed under Passkey available

### Requirement: The site directory is fetched whole and taught nothing

The system MUST fetch the site directory only when an administrator has switched on `site_directory_enabled`, which MUST be off by default. The server MUST download the whole directory in a daily background job, store it in app data and serve it at `GET /api/v1/site-directory` to signed-in users. No endpoint MUST accept a host, login address, flag or count from the browser for these checks. When a refresh fails, the last good copy MUST be kept.

#### Scenario: The directory is off by default

- **GIVEN** a fresh instance
- **WHEN** a signed-in user calls `GET /api/v1/site-directory`
- **THEN** the response is 404 and no request to the directory's operator has been made

#### Scenario: A failed refresh keeps the last copy

- **GIVEN** the site directory is on and a copy was stored yesterday
- **WHEN** today's refresh fails with a network error
- **THEN** `GET /api/v1/site-directory` still serves yesterday's copy
