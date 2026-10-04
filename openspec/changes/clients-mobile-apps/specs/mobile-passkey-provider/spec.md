## ADDED Requirements

### Requirement: Keepiq as a passkey provider
On iOS 17+ and Android 14+ the apps MUST act as a passkey provider. They offer the passkeys stored in the vault when an app or browser asks to sign in with a passkey, and save new passkeys when one is created. Passkeys use the same item JSON as the web app and the browser extension, so a passkey created on any client works on the others.

#### Scenario: Sign in with a passkey made in the browser extension
- **GIVEN** a passkey for `example.com` created with the Keepiq browser extension
- **WHEN** the user signs in to `example.com` on the phone and picks Keepiq in the passkey sheet
- **THEN** the site accepts the signature after the user unlocks

#### Scenario: Create a passkey on the phone
- **GIVEN** Keepiq as the passkey provider
- **WHEN** a site asks to create a passkey and the user chooses Keepiq
- **THEN** a `passkey` item is saved for that rpId and the web app lists it after its next load

#### Scenario: Android before version 14
- **GIVEN** a phone on Android 13
- **WHEN** the user opens the passkey settings in the app
- **THEN** the app explains that passkeys need Android 14, while passwords and codes still fill

### Requirement: Same signing rules as the browser extension
New passkeys MUST be ES256 on P-256 with an all-zero AAGUID, `none` attestation and a 16-byte random credential id. An assertion MUST sign with flags UP and UV and a DER signature. A non-zero counter MUST go up by one and be written back to the item. A request for another algorithm MUST return the platform's "not available" result so another provider can answer.

#### Scenario: Counter goes up
- **GIVEN** a stored passkey with counter 4
- **WHEN** the user signs in with it
- **THEN** the assertion carries counter 5 and the item stores 5

#### Scenario: Unsupported algorithm
- **GIVEN** a site that only accepts RS256
- **WHEN** it asks to create a passkey
- **THEN** Keepiq declines and the system offers another provider

### Requirement: The relying party comes from the operating system
The rpId MUST come from the origin the operating system or browser verified: the web origin for a website, or the app's associated domain for an app. The apps MUST NOT take an rpId from content inside the request that the system did not verify.

#### Scenario: An app without the associated domain
- **GIVEN** an app that is not associated with `example.com`
- **WHEN** it asks for a passkey with rpId `example.com`
- **THEN** the system refuses the request before Keepiq sees it, and Keepiq offers nothing
