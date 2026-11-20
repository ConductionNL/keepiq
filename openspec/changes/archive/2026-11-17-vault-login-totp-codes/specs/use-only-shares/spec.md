## MODIFIED Requirements

### Requirement: Keepiq's clients never reveal a use-only value

For a use-only copy, the web app MUST NOT show or copy the value or additional fields, MUST NOT offer editing or version reveal, and MUST leave the value out of every export and bulk share, while it MAY show the name, URL, login name and a current TOTP code, and MAY list the metadata of a login's passkeys (relying party, user name and created date). The current code MAY come from an Authenticator item or from the login's own `totp` field and MAY be copied; the web app MUST NOT show or copy the seed itself, and MUST NOT show or copy any passkey private key, nor offer to delete a passkey. The browser extension MUST fill it only on a site whose registrable domain matches the copy's URL and only into a password field, MUST NOT show or copy it, MUST NOT offer to save or update it, and MUST report each fill. The CLI MUST refuse `show`, `get key` and `copy key` for it.

#### Scenario: Web app hides the password

- **GIVEN** Bob with a use-only copy of "Supplier portal"
- **WHEN** he opens it in the secret list at /secrets
- **THEN** there MUST be no reveal toggle and no copy action for the password
- **AND** the name, URL and login name MUST be shown

#### Scenario: Web app shows the code but not the seed

- **GIVEN** Bob with a use-only copy of "Supplier portal", whose login holds a seed in its `totp` field and one passkey
- **WHEN** he opens it in the secret list at /secrets
- **THEN** the current code and its copy action MUST be shown
- **AND** the passkey MUST be listed by relying party, user name and created date only
- **AND** the seed and the passkey's private key MUST NOT be shown or copyable anywhere on the page

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
