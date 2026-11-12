## ADDED Requirements

### Requirement: Compare two versions side by side

The system MUST let the owner of a secret pick two entries in the version history panel, two earlier versions or one earlier version and the current value, and show them side by side. Both MUST be decrypted in the browser with the key already in session, the same way a single version is viewed. Every field that differs (name, address, username, value, notes and each additional field, including one added or removed) MUST be marked, and a secret value MUST stay masked until the owner reveals it. No decrypted value and no comparison result MUST be sent to the server. A recipient of a shared copy MUST NOT be offered the comparison, as recipients get no version history.

#### Scenario: An owner sees what changed between two versions

- **GIVEN** an owner whose login "Mail server" has a version from last week and a current value with a new password and a new additional field `port`
- **WHEN** the owner opens the version history panel in the secret detail sidebar, picks last week's version and the current value and chooses Compare
- **THEN** the two are shown side by side with the password row and the `port` row marked as changed
- **AND** both passwords stay masked until the owner chooses Reveal

#### Scenario: Two identical versions

- **GIVEN** two versions of a secret that differ only in their timestamps
- **WHEN** the owner compares them
- **THEN** the comparison says that no field changed
