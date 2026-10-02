## MODIFIED Requirements

### Requirement: Read Secret
The system MUST decrypt and return secret fields when the user has their master password in session. The Keepiq app UI requires the master password to be in session before any secrets are accessible — the lock screen gates all app routes.

#### Scenario: Vault route gated by lock
- GIVEN the vault is locked in this browser
- WHEN the user opens the vault list route
- THEN the app MUST send them to the lock screen, keeping the requested route as the return address

#### Scenario: Secret detail route gated by lock
- GIVEN the vault is locked in this browser
- WHEN the user opens a secret detail route, for any secret id
- THEN the app MUST send them to the lock screen, keeping the requested route as the return address

#### Scenario: Folder route gated by lock
- GIVEN the vault is locked in this browser
- WHEN the user opens a folder route, for any folder id
- THEN the app MUST send them to the lock screen, keeping the requested route as the return address

