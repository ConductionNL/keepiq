## ADDED Requirements

### Requirement: Unlock screen
When the active account is locked the popup SHALL show the unlock screen with the account's display name and server host, a Master password field with a show/hide toggle, an "Unlock" button and a "Log out" link. The master password MUST never be stored or sent anywhere; it is used to derive the unlock key and then dropped (ADR-002).

#### Scenario: Locked account opens the popup
- **GIVEN** the active account has an app password but no private key in `storage.session`
- **WHEN** the popup opens
- **THEN** the unlock screen is shown with the Master password field focused and masked

#### Scenario: Show password
- **WHEN** the user clicks the show/hide toggle
- **THEN** the field switches between masked and plain text without clearing its value

### Requirement: Unlock derives the key client-side
Unlock SHALL derive the unlock key from the master password per ADR-003 (PBKDF2-SHA256, 600 000 iterations, salt from the envelope), decrypt the cached suite's `privateKey` envelope, import the result as a non-extractable RSA-OAEP private key and store the PKCS#8 bytes per ADR-002. The server MUST NOT be contacted when the suite row is cached.

#### Scenario: Correct master password, suite cached
- **GIVEN** `suite.<accountId>` is present in `storage.local`
- **WHEN** the user submits the correct master password
- **THEN** no network request is made, `privateKeyPkcs8.<accountId>` and `unlockedAt.<accountId>` are written to `storage.session`, and the popup shows the unlocked view

### Requirement: Invalid master password
When the AES-GCM decryption of the envelope fails the extension SHALL show "Invalid master password", clear the field and stay locked. The failure MUST NOT be counted, throttled or reported to the server.

#### Scenario: Wrong password
- **WHEN** the user submits a wrong master password
- **THEN** "Invalid master password" is shown, the field is empty and focused, and `storage.session` has no key for the account

### Requirement: Unlock fetches the suite when nothing is cached
When `suite.<accountId>` is absent the extension SHALL fetch `GET /api/v1/suites` first, cache the `active` row in `storage.local` under `suite.<accountId>`, and then derive and decrypt. When the fetch fails at the network level the extension MUST show "You are offline and this vault has not been synced yet" and stay locked.

#### Scenario: First unlock after adding
- **GIVEN** the account was just added
- **WHEN** the user submits the master password
- **THEN** the suites route is called once, the active row is cached and the unlock proceeds

#### Scenario: First unlock while offline
- **GIVEN** `suite.<accountId>` is absent
- **WHEN** the suites request fails
- **THEN** the offline message is shown and the master password is discarded

### Requirement: Cached suite row lifetime
The cached suite row (`id`, `status`, `certificate`, `privateKey`, `unlockKeyEpoch`) SHALL live in `storage.local` under `suite.<accountId>` and MUST be cleared by logout, account removal, or when a later fetch returns an active suite whose `id` or `unlockKeyEpoch` differs (ADR-002). A differing suite MUST also lock the account.

#### Scenario: Master password rotated in the web app
- **GIVEN** the account is unlocked with a cached suite of `unlockKeyEpoch` 1
- **WHEN** a suites fetch returns the active suite with `unlockKeyEpoch` 2
- **THEN** the cached row is replaced, the private key is purged and the unlock screen is shown

### Requirement: Private key storage and lifetime
The private key SHALL be held as PKCS#8 bytes (base64) in `storage.session` under `privateKeyPkcs8.<accountId>` and re-imported as a non-extractable `CryptoKey` each time the background worker starts (ADR-002). On Firefox below 115, where `storage.session` is absent, the bytes MUST live only in the background page's memory. The key is cleared by lock, timeout, browser restart and extension reload, and MUST never be written to `storage.local` except under the "Never" timeout.

#### Scenario: Service worker restart while unlocked
- **GIVEN** the account is unlocked on Chrome
- **WHEN** the service worker is terminated and woken by the popup
- **THEN** the key is re-imported from `storage.session` and the account is still "Unlocked"

#### Scenario: Browser restart
- **WHEN** the browser is closed and reopened
- **THEN** every account is "Locked" and `storage.session` is empty

### Requirement: Manual lock
The unlocked view and the account switcher SHALL offer a "Lock" action that purges the private key from `storage.session` (or memory) and the derived key from memory, and shows the unlock screen. Lock MUST keep the app password, cached suite row, `vaultCache.<accountId>` and settings.

#### Scenario: Lock from the unlocked view
- **WHEN** the user clicks "Lock"
- **THEN** `privateKeyPkcs8.<accountId>` is removed, the unlock screen is shown and no server request is made

### Requirement: Timeout options and defaults
Each account SHALL have a vault timeout and a timeout action stored in `storage.local` under `settings.<accountId>`. Options: Immediately, 1 minute, 5 minutes, 15 minutes, 30 minutes, 1 hour, 4 hours, On system lock, On browser restart, Never, Custom (minutes). Actions: Lock, Log out. Defaults: 15 minutes and Lock (ADR-002); a browser restart locks regardless of the chosen option because `storage.session` does not survive it. The maximum timeout and a forced action MUST be read from one policy object so an admin policy can clamp them later. The option picker itself is owned by ext-settings.

#### Scenario: New account
- **WHEN** an account is added
- **THEN** `settings.<accountId>` is `{ vaultTimeout: 15, vaultTimeoutAction: 'lock' }`

### Requirement: Idle timeout enforcement
For a timed option the extension SHALL lock the account when the time since the user's last interaction with the extension reaches the timeout. Last interaction is refreshed by every popup message and stored in `storage.session` as `lastInteractionAt`. The check MUST run on a `browser.alarms` tick at most every minute while any account is unlocked and again on every popup open, so a sleeping worker cannot extend a session.

#### Scenario: Timeout elapsed while the popup is closed
- **GIVEN** the timeout is 5 minutes and the last interaction was 6 minutes ago
- **WHEN** the alarm fires or the popup opens
- **THEN** the account is locked before any vault state is returned to the popup

#### Scenario: Popup interaction resets the clock
- **GIVEN** the timeout is 5 minutes
- **WHEN** the user interacts with the popup after 4 minutes
- **THEN** `lastInteractionAt` is refreshed and the account stays unlocked for another 5 minutes

### Requirement: Immediately and On system lock
"Immediately" SHALL lock the account when the popup closes. "On system lock" SHALL lock the account when `browser.idle` reports the `locked` state. "On browser restart" relies on `storage.session` being cleared and needs no timer.

#### Scenario: Immediately
- **GIVEN** the timeout is Immediately
- **WHEN** the popup closes
- **THEN** the account is locked before the popup is reopened

#### Scenario: Operating system locks the screen
- **GIVEN** the timeout is On system lock
- **WHEN** the idle state changes to `locked`
- **THEN** the account is locked

### Requirement: Timeout action Log out
When the timeout action is "Log out" the elapsed timeout SHALL purge the private key, the app password, the cached suite row and `vaultCache.<accountId>`, keep the identity record and settings, and leave the account in the "Logged out" state so the "Log in again" screen is shown next.

#### Scenario: Log out on timeout
- **GIVEN** the action is Log out and the timeout elapsed
- **WHEN** the popup opens
- **THEN** the "Log in again" screen is shown and the account is listed as "Logged out" in the switcher

### Requirement: Never timeout
Choosing "Never" SHALL show the warning "Your vault stays unlocked until you lock it, and the key is stored on disk" and requires confirmation. With "Never" the PKCS#8 bytes MAY be written to `storage.local` under `neverLockKey.<accountId>`; this is the only case key material touches `storage.local` (ADR-002). Manual lock, logout, account removal and changing the timeout to anything else MUST delete that key.

#### Scenario: Never survives a restart
- **GIVEN** the timeout is Never and the account is unlocked
- **WHEN** the browser restarts
- **THEN** the account is still "Unlocked" without asking for the master password

#### Scenario: Leaving Never
- **GIVEN** `neverLockKey.<accountId>` exists
- **WHEN** the timeout is changed to any other option
- **THEN** `neverLockKey.<accountId>` is removed and the key remains only in `storage.session`

### Requirement: Lock and logout labelled distinctly
Every button, link, status label and message SHALL use "Lock" for purging the key only and "Log out" for purging the app password. The two words MUST never be used for the same action.

#### Scenario: Unlock screen actions
- **WHEN** the unlock screen is shown
- **THEN** the primary button reads "Unlock" and the secondary link reads "Log out", and no element reads "Lock"

### Requirement: Alternative unlock method hook
The unlock flow SHALL expose one entry point that takes an unlock method (`masterPassword` today) so ext-settings can add PIN unlock without touching the state machine. The popup MUST NOT show a PIN option in this change.

#### Scenario: Only master password offered
- **WHEN** the unlock screen renders
- **THEN** the master password is the only unlock method shown
