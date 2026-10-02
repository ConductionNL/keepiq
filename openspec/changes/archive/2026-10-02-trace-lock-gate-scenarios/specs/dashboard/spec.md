## ADDED Requirements

### Requirement: Dashboard Requires An Unlocked Vault [MVP]
The dashboard MUST only render after the user has unlocked the vault in this browser. The lock screen is the route in front of it.

#### Scenario: Dashboard route gated by lock
- GIVEN the vault is locked in this browser
- WHEN the user opens the dashboard route
- THEN the app MUST send them to the lock screen, keeping the requested route as the return address
