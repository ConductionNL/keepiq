## ADDED Requirements

### Requirement: Inactivity lock

The system MUST lock the web vault after the configured period without user activity. Pointer movement, key presses, scrolling and touch MUST reset the period. Activity in another browser tab MUST NOT keep a locked tab unlocked, and a lock MUST still clear the master key from memory.

#### Scenario: An active user is not locked out

- **GIVEN** a user with a 10 minute timeout who has been working for 25 minutes with clicks every minute
- **WHEN** the user keeps working
- **THEN** the vault stays unlocked

#### Scenario: An idle user is locked

- **GIVEN** a user with a 10 minute timeout who stops interacting
- **WHEN** ten minutes pass
- **THEN** the lock screen is shown and the master key is cleared

#### Scenario: Manual lock still works

- **GIVEN** an unlocked user
- **WHEN** the user chooses Lock vault from the menu
- **THEN** the lock screen is shown at once

### Requirement: Saved session timeout

The system MUST let a user choose a timeout in personal settings, MUST persist it through `PUT` on the session timeout preference, and MUST apply the saved value when the vault is unlocked in a new page load. The option Nextcloud session MUST mean no separate idle timer and MUST NOT be converted to another value.

#### Scenario: The choice survives a reload

- **GIVEN** a user who picked 30 minutes in personal settings
- **WHEN** the user reloads the page and unlocks
- **THEN** the vault uses a 30 minute timeout and the select shows 30 minutes

#### Scenario: Nextcloud session means no idle timer

- **GIVEN** a user who picked Nextcloud session
- **WHEN** the user stays idle for an hour within the Nextcloud session
- **THEN** the vault does not lock on an idle timer
