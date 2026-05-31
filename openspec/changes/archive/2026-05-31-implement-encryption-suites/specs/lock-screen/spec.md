## ADDED Requirements

### Requirement: Lock Screen Route
The system MUST implement a full-page lock screen at the `/lock` route. The lock screen MUST display a master password input field, the `PasswordStrengthMeter` component (during first-time setup only), and a submit button. The lock screen MUST NOT show any vault content (secrets, folders, navigation items).

The lock screen component MUST use `@conduction/nextcloud-vue` layout components where applicable and follow the NL Design System double-fallback CSS pattern (cn- prefix).

#### Scenario: Lock screen displays on session expiry
- **WHEN** the session timeout elapses or the CryptoKey is null
- **THEN** the user MUST see a full-page lock screen (not an overlay)
- **AND** the lock screen MUST show the Doriath logo, a master password input, and a submit button
- **AND** no vault content MUST be visible behind or around the lock screen

#### Scenario: First-time setup lock screen
- **WHEN** a user with no EncryptionSuite opens Doriath
- **THEN** the lock screen MUST show "Set up your master password" with the `PasswordStrengthMeter`
- **AND** a confirmation field for the master password
- **AND** the submit button MUST trigger suite creation after password confirmation

### Requirement: Vue Router Navigation Guard
The system MUST implement a `beforeEach` navigation guard on the Vue Router that checks `sessionStore.isLocked`. If the session is locked (CryptoKey is null) and the target route is not `/lock`, the guard MUST redirect to `/lock`. If the session is unlocked and the target route is `/lock`, the guard MUST redirect to the dashboard.

The router file MUST be at `src/router/index.js` using hash mode. The guard MUST use the Pinia `sessionStore`.

#### Scenario: Locked user navigates to vault
- **WHEN** a user with no active CryptoKey in memory navigates to any route except `/lock`
- **THEN** the router guard MUST redirect to `/lock`

#### Scenario: Unlocked user navigates to lock screen
- **WHEN** a user with an active CryptoKey navigates to `/lock`
- **THEN** the router guard MUST redirect to the dashboard route

#### Scenario: Deep link while locked
- **WHEN** a user follows a deep link to a specific secret while their session is locked
- **THEN** the router guard MUST redirect to `/lock`
- **AND** after successful unlock, the user MUST be redirected to the originally requested route

### Requirement: Session Expiry Detection
The system MUST detect session expiry via two mechanisms:
1. A `setInterval` timer (every 10 seconds) that calls `sessionStore.checkTimeout()`
2. A `visibilitychange` event listener that calls `sessionStore.checkTimeout()` when the tab becomes visible

When the timeout has elapsed (current time > lastActivity + timeout), the session store MUST immediately null the CryptoKey and AES key, and the navigation guard MUST redirect to `/lock` on the next route transition.

Activity MUST be tracked by updating `lastActivity` on user interactions (route changes, API calls, click events within Doriath views).

#### Scenario: Session expires while tab is active
- **WHEN** the user has been idle for longer than the configured timeout
- **AND** the 10-second interval check fires
- **THEN** the CryptoKey MUST be cleared immediately
- **AND** the user MUST be redirected to the lock screen

#### Scenario: Session expires while tab is backgrounded
- **WHEN** the user switches to another tab and the timeout elapses
- **AND** the user switches back to the Doriath tab
- **THEN** the `visibilitychange` listener MUST trigger a timeout check
- **AND** the CryptoKey MUST be cleared and the lock screen displayed

### Requirement: Tab Close Key Clearing
The system MUST register a `beforeunload` event listener that calls `sessionStore.lock()` to null the CryptoKey and AES key. This is a best-effort measure — the real guarantee is that JavaScript memory is released when the tab/window is destroyed by the browser.

#### Scenario: User closes last Doriath tab
- **WHEN** the user closes the last browser tab running Doriath
- **THEN** JavaScript memory containing the CryptoKey MUST be released by the browser
- **AND** opening Doriath again MUST show the lock screen

#### Scenario: User closes one of multiple tabs
- **WHEN** the user has Doriath open in two tabs and closes one
- **THEN** the remaining tab MUST retain its CryptoKey (same-origin Pinia store is per-page-context)
- **AND** the closed tab's memory MUST be released

### Requirement: Cross-Device Session Isolation
Unlocking Doriath on one device MUST NOT propagate the session to other devices. Each device maintains its own in-memory CryptoKey independently. There is no server-side session state to synchronize.

#### Scenario: Cross-device isolation
- **WHEN** a user unlocks Doriath on device A
- **AND** opens Doriath on device B
- **THEN** device B MUST show the lock screen
- **AND** device B MUST require independent master password entry

### Requirement: Migration Paused Screen
When a user opens Doriath and a SuiteMigration is in `in_progress` status for their account, the system MUST display a "Migration Paused" screen instead of the normal lock screen. This screen MUST show the number of remaining unmigrated secrets and require the user's master password to resume migration.

#### Scenario: User reopens during active migration
- **WHEN** a user opens Doriath and has a SuiteMigration with status `in_progress`
- **THEN** the system MUST display a "Migration Paused" screen
- **AND** show the count of secrets still referencing the old EncryptionSuite
- **AND** require the master password to resume migration
- **AND** NOT allow normal vault access until migration completes or the user explicitly defers
