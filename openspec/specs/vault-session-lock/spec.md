# Vault session lock Specification

**Status**: done

**OpenSpec changes:**
- [crypto-session-timeout-and-inactivity-lock](../../changes/archive/2026-09-29-crypto-session-timeout-and-inactivity-lock/) _(archived 2026-09-29)_

## Purpose
The web vault locks itself after a period without user activity, and the user picks that period once in the user settings. Parity rows crypto-06 and crypto-07.

## Requirements

### Requirement: Inactivity lock

The system MUST lock the web vault after the configured period without user activity. Pointer movement, key presses, scrolling and touch MUST reset the period. Activity in another browser tab MUST NOT keep a locked tab unlocked, and a lock MUST still clear the master key from memory.

#### Scenario: An active user is not locked out

@e2e exclude A 25 minute real-time flow; covered by vitest tests/store/session.timeout.spec.js 'keeps an active user unlocked past the timeout' with fake timers.

- **GIVEN** a user with a 10 minute timeout who has been working for 25 minutes with clicks every minute
- **WHEN** the user keeps working
- **THEN** the vault stays unlocked

#### Scenario: An idle user is locked

@e2e exclude A ten minute real-time wait; covered by vitest tests/store/session.timeout.spec.js 'locks an idle user after the timeout and clears the key'.

- **GIVEN** a user with a 10 minute timeout who stops interacting
- **WHEN** ten minutes pass
- **THEN** the lock screen is shown and the master key is cleared

#### Scenario: Manual lock still works

@e2e exclude The menu action calls sessionStore.lock(), covered with the lock transition by tests/components/appLockWiring.spec.js.

- **GIVEN** an unlocked user
- **WHEN** the user chooses Lock vault from the menu
- **THEN** the lock screen is shown at once

### Requirement: Saved session timeout

The system MUST let a user choose a timeout in personal settings, MUST persist it through `PUT` on the session timeout preference, and MUST apply the saved value when the vault is unlocked in a new page load. When neither the user nor the administrator chose, the timeout MUST be ten minutes. The option Nextcloud session MUST mean no separate idle timer and MUST NOT be converted to another value.

#### Scenario: The choice survives a reload

@e2e exclude Needs a vault with a master password on the test instance to unlock after the reload; covered by vitest tests/store/session.timeout.spec.js 'loads the saved choice' and 'saves a choice through PUT', and PHPUnit SettingsServiceSessionTimeoutTest.

- **GIVEN** a user who picked 30 minutes in personal settings
- **WHEN** the user reloads the page and unlocks
- **THEN** the vault uses a 30 minute timeout and the select shows 30 minutes

#### Scenario: Nextcloud session means no idle timer

@e2e exclude An hour of real-time idling; covered by vitest tests/store/session.timeout.spec.js 'never locks on an idle timer for the Nextcloud session choice'.

- **GIVEN** a user who picked Nextcloud session
- **WHEN** the user stays idle for an hour within the Nextcloud session
- **THEN** the vault does not lock on an idle timer
