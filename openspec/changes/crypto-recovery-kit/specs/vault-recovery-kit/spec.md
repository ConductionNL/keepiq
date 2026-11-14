## ADDED Requirements

### Requirement: A user can create a recovery kit that reopens their own vault

The web app MUST let a signed-in user with an unlocked vault create a recovery kit. The browser MUST generate a random recovery code of at least 128 bits, derive a wrapping key from it with Argon2id, wrap the user's current suite private key with that key, and send only the wrapped copy. The system MUST keep at most one wrapped copy per user and suite; creating a new kit MUST replace the old copy. The kit MUST be offered as a printable page and as a text file holding the code, the account and the date.

#### Scenario: A kit is created without the code leaving the browser

- **GIVEN** a user with an unlocked vault
- **WHEN** they create a recovery kit
- **THEN** the server stores one wrapped copy for that user and suite
- **AND** no request from the browser contains the recovery code or the unwrapped private key
- **AND** the user can print the kit and download it as a text file

#### Scenario: A new kit replaces the old one

- **GIVEN** a user who created a recovery kit before
- **WHEN** they create a new kit
- **THEN** the old wrapped copy is deleted
- **AND** the old recovery code no longer unwraps anything

### Requirement: The recovery code reopens the vault and sets a new master password

The lock screen MUST offer a way in for a user who lost their master password. When the user enters their recovery code, the browser MUST fetch the user's wrapped copy, unwrap the suite private key, and require a new master password, applied through the routine master password change. After a successful recovery the system MUST delete the spent wrapped copy and the web app MUST offer a new kit.

#### Scenario: A user who lost the master password gets back in

- **GIVEN** a user who created a recovery kit and forgot their master password
- **WHEN** they choose "I lost my master password" on the lock screen and enter the recovery code
- **THEN** their vault unlocks with every secret readable
- **AND** they must set a new master password before they continue
- **AND** the spent kit no longer works and a new kit is offered

#### Scenario: A wrong code opens nothing

- **WHEN** a user enters a recovery code that does not match their kit
- **THEN** the vault stays locked and the attempt counts against the rate limit

### Requirement: Recovery is limited to the owner and leaves a trail

The system MUST return a wrapped recovery copy only to the user it belongs to, MUST rate limit fetches of it, and MUST write the creation, use and revocation of a kit to the audit trail. An administrator MUST NOT be able to read or use a user's wrapped copy.

#### Scenario: Another user cannot fetch the copy

- **WHEN** a user requests another user's wrapped recovery copy
- **THEN** the request is refused and nothing is returned

#### Scenario: Kit events are audited

- **WHEN** a user creates a kit, recovers with it, or replaces it
- **THEN** the audit trail records each event with the user and the time
