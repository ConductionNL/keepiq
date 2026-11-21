## MODIFIED Requirements

### Requirement: Immediately and On system lock
"Immediately" SHALL lock the account when the popup closes. "On system lock" SHALL lock the account when `browser.idle` reports the `locked` state, and is available only where the browser has the `idle` API; without it the background starts without listening for idle states. "On browser restart" relies on `storage.session` being cleared and needs no timer.

#### Scenario: Immediately
- **GIVEN** the timeout is Immediately
- **WHEN** the popup closes
- **THEN** the account is locked before the popup is reopened

#### Scenario: Operating system locks the screen
- **GIVEN** the timeout is On system lock
- **WHEN** the idle state changes to `locked`
- **THEN** the account is locked

#### Scenario: No idle API
- **GIVEN** a browser where `browser.idle` is undefined, as Safari
- **WHEN** the background starts
- **THEN** it starts without error, and the timed options and Immediately still lock
