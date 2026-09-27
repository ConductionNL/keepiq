## ADDED Requirements

### Requirement: User-chosen idle lock period with an administrator maximum

The extension MUST let the user choose the idle lock period per paired account from 1, 5, 15, 30, 60 and 240 minutes, with 15 as the default, and MUST store the choice in extension storage. On every unlock the extension MUST read `maxIdleMinutes` from `GET /api/v1/extension/policy` and MUST use the lower of the user's choice and that maximum. The lock on OS or browser lock, on worker termination and on manual lock MUST stay in force whatever the period.

#### Scenario: A user picks five minutes

- **GIVEN** a vault owner with an unlocked extension and an administrator maximum of 240 minutes
- **WHEN** they choose 5 minutes in the popup settings view and leave the browser idle for 5 minutes
- **THEN** the extension MUST lock and the next fill MUST ask for an unlock

#### Scenario: The administrator maximum wins

- **GIVEN** a vault owner who chose 240 minutes
- **WHEN** an administrator sets the extension maximum to 30 minutes in the Keepiq admin settings and the owner next unlocks the extension
- **THEN** the extension MUST lock after 30 idle minutes
- **AND** the popup settings view MUST show that 60 and 240 minutes exceed the organisation's maximum
