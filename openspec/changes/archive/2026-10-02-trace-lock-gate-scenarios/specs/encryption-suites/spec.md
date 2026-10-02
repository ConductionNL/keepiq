## MODIFIED Requirements

(Session requirement in encryption-suites: one scenario added.)

#### Scenario: User views lock screen
- GIVEN a user who has an encryption suite and has not unlocked the vault in this browser
- WHEN they open Keepiq
- THEN the lock screen MUST fill the page, not sit over the app as an overlay
- AND it MUST offer the master password form to unlock the vault

