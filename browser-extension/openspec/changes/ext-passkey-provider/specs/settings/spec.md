## MODIFIED Requirements

### Requirement: Notification prompts and excluded domains
The extension SHALL store per account the toggles Ask to add login (default on), Ask to update existing login (default on) and Ask to save and use passkeys (default on, as in Bitwarden) and an Excluded domains list (default empty). Each list entry is a base domain, by the same public-suffix-aware rule ext-autofill matches and excludes with (`baseDomain` in `src/vault/match.ts`). The Excluded domains view offers Add current site, which adds the current tab's base domain, a free-text field, and a remove control per entry. Free text is lowercased, and a host or pasted URL is reduced to its base domain. Duplicate, empty and unreadable entries are refused. These entries follow the "Coming later" rule of the Autofill settings until ext-autofill delivers the save prompts, and Ask to save and use passkeys until ext-passkey-provider delivers the provider. Ask to save and use passkeys is stored as `askPasskeys` in `settings.<id>` in `storage.local`, kept through a logout and removed with the account; a change applies to the next passkey request without reloading the page.

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

#### Scenario: Turning passkeys off
- **GIVEN** the provider is delivered and Ask to save and use passkeys is on
- **WHEN** the user turns it off and a page on an open tab then calls `navigator.credentials.get({ publicKey })`
- **THEN** the browser's own `get` runs and no Keepiq window opens
