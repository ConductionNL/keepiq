## ADDED Requirements

### Requirement: Pair a phone with Login Flow v2
The apps MUST connect to a Nextcloud account through Nextcloud Login Flow v2. The user types the server address, signs in on the server's own login page in the system browser, and the app receives an app password. Then it calls `POST /api/v1/extension/pair`. Manual entry of an app password MUST remain as a fallback. Up to 5 accounts can be paired on one device.

#### Scenario: Sign in through the browser
- **GIVEN** a reachable https server
- **WHEN** the user enters its address and signs in on the login page, including two-factor
- **THEN** the app receives an app password, the pair call succeeds and the account appears in the app

#### Scenario: The user abandons the login
- **GIVEN** a started login flow
- **WHEN** the user closes the browser tab, or 20 minutes pass
- **THEN** the app stops polling and shows the address form again, with nothing stored

#### Scenario: Unsupported server
- **GIVEN** a server whose pair response reports an `apiVersion` the app does not support, or without keepiq installed
- **WHEN** the user pairs
- **THEN** the app explains what is missing and stores no account

### Requirement: Unpair removes the account everywhere
Unpairing MUST call `/api/v1/extension/unpair`, revoke the app password with `DELETE /ocs/v2.php/core/apppassword`, and delete the account's store, biometric and PIN wraps and autofill index on the device.

#### Scenario: Unpair a phone
- **GIVEN** a paired account
- **WHEN** the user unpairs it
- **THEN** the app password stops working on the server, and the system autofill no longer offers that account's logins

### Requirement: Unlock with the master password
The app MUST unlock by deriving the unlock key from the master password and opening the private-key envelope on the device. The decrypted private key MUST live in memory only. When the server reports `unlockBlocked`, the app MUST explain the reason, for example that two-factor authentication must be set up first, and MUST NOT offer unlock.

#### Scenario: Wrong master password
- **GIVEN** the unlock screen
- **WHEN** the user enters a wrong master password
- **THEN** the envelope does not open, the app says so, and nothing is unlocked

#### Scenario: Two-factor required
- **GIVEN** an account whose organisation requires two-factor and the user has none
- **WHEN** the user opens the app
- **THEN** the app says two-factor must be set up in Nextcloud first, and shows no unlock field

### Requirement: Biometric and PIN unlock
After a master-password unlock, the user MAY turn on biometric unlock or a PIN. Biometric unlock MUST wrap the unlock key with a hardware-backed key that requires the user's biometric and is invalidated when a new biometric is enrolled. A PIN MUST be 6 to 64 characters, wrap the unlock key with Argon2id, and be wiped after 5 wrong tries. Both wraps MUST be deleted when the suite's `unlockKeyEpoch` changes.

#### Scenario: Unlock with a fingerprint or face
- **GIVEN** biometric unlock turned on
- **WHEN** the user opens the app and passes the biometric prompt
- **THEN** the vault unlocks without the master password

#### Scenario: A new fingerprint is added to the phone
- **GIVEN** biometric unlock turned on
- **WHEN** someone enrols a new fingerprint or face in the system settings
- **THEN** the next unlock asks for the master password, and biometric unlock must be turned on again

#### Scenario: Five wrong PINs
- **GIVEN** PIN unlock turned on
- **WHEN** the user enters a wrong PIN five times
- **THEN** the PIN is wiped and the app asks for the master password

### Requirement: Automatic locking
The app MUST lock after an idle time the user picks from 1, 5, 15, 30, 60 or 240 minutes (default 15), capped by the organisation's `maxIdleMinutes`. It MUST also lock when the device locks. The AutoFill extension on iOS and the autofill services on Android MUST honour the same lock state.

#### Scenario: The organisation caps the idle time
- **GIVEN** an organisation `maxIdleMinutes` of 5 and a user choice of 60
- **WHEN** the app is idle for 5 minutes
- **THEN** it locks

#### Scenario: The phone is locked
- **GIVEN** an unlocked vault
- **WHEN** the user locks the phone
- **THEN** the vault is locked the next time the app or a fill sheet opens
