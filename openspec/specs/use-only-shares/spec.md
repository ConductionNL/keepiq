# use-only-shares Specification

## Purpose
An owner shares a secret so the recipient can sign in with it through the browser extension but never view, copy, export or edit its value in Keepiq's own clients.

## Requirements

### Requirement: Owners can share a secret as use-only

The system MUST let the owner mark a user share, a group share, or a team-folder membership with grade `read` as use-only, and MUST refuse use-only on a `write` or `manage` membership. The flag MUST be materialised on each recipient copy; a copy reached through several grants MUST be use-only only when every grant is use-only. Only the owner (or a team-folder manager for memberships) MUST be able to change the flag.

#### Scenario: Owner shares a login as use-only

- **GIVEN** a vault owner in the share dialog for the secret "Supplier portal"
- **WHEN** they share it with Bob and tick "Use only (can sign in, cannot view or copy)"
- **THEN** Bob's copy MUST carry `useOnly` true

#### Scenario: A second, unrestricted grant lifts use-only

- **GIVEN** Bob holds a use-only copy through a direct share
- **WHEN** the same secret reaches Bob through a team folder where he is a `read` member without use-only
- **THEN** Bob's copy MUST carry `useOnly` false

### Requirement: The share dialog states the limit of use-only

The share dialog and the team-folder dialog MUST state, next to the use-only option, that Keepiq's apps will not show or copy the value, that someone with technical skill can still read it from their own device, and that the owner should rotate it when access ends.

#### Scenario: The owner sees the limit before choosing

- **GIVEN** a vault owner opening the share dialog
- **WHEN** the use-only option is shown
- **THEN** the explanation of its limit MUST be visible next to it

### Requirement: Keepiq's clients never reveal a use-only value

For a use-only copy, the web app MUST NOT show or copy the value or additional fields, MUST NOT offer editing or version reveal, and MUST leave the value out of every export and bulk share, while it MAY show the name, URL, login name and a current TOTP code. The browser extension MUST fill it only on a site whose registrable domain matches the copy's URL and only into a password field, MUST NOT show or copy it, MUST NOT offer to save or update it, and MUST report each fill. The CLI MUST refuse `show`, `get key` and `copy key` for it.

#### Scenario: Web app hides the password

- **GIVEN** Bob with a use-only copy of "Supplier portal"
- **WHEN** he opens it in the secret list at /secrets
- **THEN** there MUST be no reveal toggle and no copy action for the password
- **AND** the name, URL and login name MUST be shown

#### Scenario: The extension signs Bob in

- **GIVEN** Bob with a use-only copy whose URL is `https://portal.supplier.example`
- **WHEN** he chooses it in the extension popup on `portal.supplier.example`
- **THEN** the extension MUST fill the login and password fields and report the fill
- **AND** the popup MUST NOT show or copy the password

#### Scenario: The extension refuses another site

- **GIVEN** Bob with the same use-only copy
- **WHEN** he is on `attacker.example.net`
- **THEN** the extension MUST NOT offer or fill the copy

#### Scenario: The CLI refuses to print it

- **GIVEN** Bob with a use-only copy
- **WHEN** he runs `keepiq show <id>`
- **THEN** the CLI MUST refuse and print that the secret is use-only

### Requirement: The server refuses what it can enforce

The system MUST refuse any share whose source is a use-only copy (direct, batch, group, team-folder fan-out, link share, delegation and handover), MUST refuse recipient updates and sync of a use-only copy, MUST refuse the recipient's version history ciphertext for it, and MUST leave its value ciphertext out of the recipient's GDPR export.

#### Scenario: A modified client cannot share it onward

- **GIVEN** Bob with a use-only copy
- **WHEN** a script with Bob's session calls `POST /api/v1/shares/register-batch` with that copy as source
- **THEN** the system MUST refuse the request and create no copy

#### Scenario: Bob cannot overwrite it

- **GIVEN** Bob with a use-only copy
- **WHEN** Bob calls `PUT /api/v1/secrets/{id}/sync` for it
- **THEN** the system MUST refuse the request

### Requirement: Each use is recorded

The system MUST provide `POST /api/v1/secrets/{id}/used` for the recipient of a use-only copy, MUST record a `secret.used` audit event with identifiers only, and MUST show it in the owner's activity for the source secret.

#### Scenario: The owner sees who used the login

- **GIVEN** Bob filled a use-only copy through the extension
- **WHEN** the owner opens the activity tab of "Supplier portal"
- **THEN** the tab MUST list Bob's use with its time
