## ADDED Requirements

### Requirement: No send while offline
When the server cannot be reached, the Send tab MUST say "You are offline. A send needs a connection to Keepiq." and MUST NOT let the user create a send. A send that fails for want of a connection MUST say the same.

#### Scenario: Offline
@e2e exclude Browser-extension popup. Covered by tests/extension/sendDetails.spec.js ("allows no send while offline").
- **GIVEN** an account whose last sync found no server
- **WHEN** the Send tab opens
- **THEN** it shows the offline note and Create link is off

### Requirement: Say what a send is and what went wrong
Each send in the list MUST show its kind and time, how often it was opened of how often it may be, when it expires, and whether it has a password. A list that fails to load MUST offer to try again. Ending a send MUST ask first; a send that is already gone counts as ended. A refused send MUST show the server's own message when it gives one. While Argon2id protects a send with its password, the tab MUST say so and allow one press; a browser that cannot run Argon2id MUST say it cannot protect a send with a password. Send MUST be offered on a login only.

#### Scenario: The list
@e2e exclude Browser-extension popup. Covered by tests/extension/sendDetails.spec.js ("lists what each send is, and asks before ending one"), ("offers to try again when the list fails") and ("says when a send expires").
- **GIVEN** a text send opened once of twice, expiring in three hours, with a password
- **WHEN** the Send tab lists it
- **THEN** it reads "1 of 2 opened, expires in 3 hours, password", and End asks first

#### Scenario: Errors
@e2e exclude Browser-extension worker. Covered by tests/extension/sendDetails.spec.js ("says offline, the server message, or the error"), ("passes on the server's own message when a send is refused"), ("treats a send that is already gone as ended") and ("refuses a password-protected send where Argon2id cannot run").
- **GIVEN** a server that refuses a send with a message, a send already gone, or a browser without WebAssembly
- **WHEN** the user creates, ends or protects a send
- **THEN** the message is the server's, ending succeeds, or the user is told a password cannot be used

#### Scenario: Logins only
@e2e exclude Browser-extension popup. Covered by tests/extension/sendDetails.spec.js ("offers Send on a login only").
- **GIVEN** a note and a login
- **WHEN** each is opened
- **THEN** only the login offers Send
