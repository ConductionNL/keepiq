## ADDED Requirements

### Requirement: The offline store keeps a login's code and passkeys
The core MUST keep a secret's `totp` and `passkeys` fields in the offline store and the autofill index as the ciphertext the server sent, next to `key`, `login` and `additionalFields`, and MUST NOT decrypt them for the list, the index or the store. A store created before these fields existed MUST be migrated and then fully synced, so a login's code and passkeys are available without the login changing first.

#### Scenario: Reading a login's code offline
- **GIVEN** a login with a seed in its `totp` field, synced to the phone
- **WHEN** the phone is offline and the user opens the login
- **THEN** the detail screen shows the current code

#### Scenario: Updating the app with a store from before
- **GIVEN** a phone whose offline store was synced before the store kept `totp` and `passkeys`
- **WHEN** the updated app syncs for the first time
- **THEN** it reads the whole vault again and stores both fields for every login that has them
