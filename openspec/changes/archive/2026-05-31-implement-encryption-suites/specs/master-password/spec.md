## ADDED Requirements

### Requirement: Master Password Strength Enforcement
The system MUST enforce a minimum strength floor on master passwords using entropy-based scoring via zxcvbn. The strength meter MUST provide live feedback while the user types. The submit button MUST be disabled until both the minimum length and minimum score are met.

| Setting | App minimum (hardcoded) | Admin-configurable range | Default |
|---------|------------------------|--------------------------|---------|
| Minimum length | 12 characters | 12-20 characters | 12 |
| Minimum score | zxcvbn >= 3 | 3-4 | 3 |

Score meaning: 0 = trivial, 1 = very guessable, 2 = somewhat guessable, 3 = strong (resists online attacks), 4 = very strong (resists offline attacks).

Settings MUST be stored in Nextcloud app config via `SettingsService` and exposed to the frontend via the settings API. The Nextcloud OCP interfaces used: `OCP\IConfig` for app config storage.

#### Scenario: Weak password rejected
- **WHEN** a user submits a master password with zxcvbn score below the configured minimum
- **THEN** the system MUST reject it and display feedback from zxcvbn explaining why (e.g., "too short", "common pattern", "too guessable")

#### Scenario: Password below minimum length
- **WHEN** a user submits a master password shorter than the configured minimum length
- **THEN** the system MUST reject it regardless of zxcvbn score

#### Scenario: Admin raises the floor
- **WHEN** an admin sets minimum score to 4 and minimum length to 20
- **THEN** users setting a new master password MUST meet both thresholds

#### Scenario: Admin cannot lower below app minimum
- **WHEN** an admin attempts to set minimum length below 12 or minimum score below 3
- **THEN** the system MUST reject the configuration change

### Requirement: Live Strength Feedback
The `PasswordStrengthMeter` Vue component MUST display real-time feedback as the user types their master password. Input MUST be debounced at 300ms. The component MUST display:
1. A colored progress bar indicating strength (red=0-1, orange=2, green=3-4)
2. Text feedback from zxcvbn's `feedback.warning` and `feedback.suggestions`
3. Character count vs. minimum length

The component MUST emit a `strength-change` event with the current score for parent forms to enable/disable submit buttons.

#### Scenario: User types weak password
- **WHEN** a user types "password123" in the master password field
- **THEN** the strength meter MUST show a red bar with score 0 or 1
- **AND** display zxcvbn feedback such as "This is a very common password"
- **AND** the submit button MUST remain disabled

#### Scenario: User types strong password
- **WHEN** a user types a password with zxcvbn score >= configured minimum and length >= configured minimum
- **THEN** the strength meter MUST show a green bar
- **AND** the submit button MUST become enabled

### Requirement: AES Key Derivation from Master Password
The system MUST derive a 256-bit AES-GCM key from the master password using PBKDF2-SHA256 with 600,000 iterations. The derivation MUST happen entirely in the browser via `crypto.subtle.deriveKey`. A 16-byte random salt MUST be generated per derivation and stored alongside the encrypted private key blob.

The master password MUST NOT be sent to the server. The AES-derived key MUST NOT be sent to the server. The AES-derived key MUST NOT be stored in `localStorage`, `sessionStorage`, `IndexedDB`, or cookies.

The Nextcloud OCP interface `OCP\ISession` MUST NOT be used for master password or AES key storage.

#### Scenario: Key derivation on unlock
- **WHEN** a user enters their master password on the lock screen
- **THEN** the browser MUST derive a 256-bit AES-GCM key using PBKDF2-SHA256 (600K iterations)
- **AND** use the derived key to decrypt the private key blob fetched from the API
- **AND** import the decrypted private key as a WebCrypto CryptoKey with `extractable: false`
- **AND** store the CryptoKey in the Pinia session store (JS memory only)

#### Scenario: Master password never sent to server
- **WHEN** a user enters their master password
- **THEN** no HTTP request MUST contain the plaintext master password or the AES-derived key

### Requirement: Routine Master Password Change
The system MUST allow a user to change their master password for routine hygiene reasons. The RSA key pair MUST remain unchanged. Only the AES wrapping of the private key changes.

#### Scenario: Routine password change
- **WHEN** a user provides their current master password and a new master password meeting the strength floor
- **THEN** the browser MUST derive the old AES key from the current master password
- **AND** decrypt the private key blob using the old AES key
- **AND** derive a new AES key from the new master password (new random salt)
- **AND** re-encrypt the private key using the new AES key
- **AND** send the new encrypted blob to the server via PUT `/api/v1/suites/{id}/private-key`
- **AND** no secrets are affected (same RSA key pair)

#### Scenario: Wrong current password
- **WHEN** a user provides an incorrect current master password during routine change
- **THEN** the browser MUST fail to decrypt the private key blob (GCM authentication failure)
- **AND** display an error message "Current master password is incorrect"
- **AND** NOT send any update to the server

### Requirement: Session Timeout Configuration
The session timeout MUST be configurable per user. Available options: Nextcloud session duration, 10 minutes, 30 minutes. The default MUST be 10 minutes. The setting MUST be stored via user preferences (`OCP\IConfig::setUserValue`).

The timeout is enforced client-side only (the browser clears the CryptoKey). The server has no encryption session state to expire.

#### Scenario: User configures 30-minute timeout
- **WHEN** a user sets their session timeout to 30 minutes
- **THEN** the Pinia session store MUST use 1,800,000ms as the timeout value
- **AND** the CryptoKey MUST be cleared after 30 minutes of inactivity

#### Scenario: User configures Nextcloud session duration
- **WHEN** a user selects "Nextcloud session duration" as the timeout
- **THEN** the system MUST use the Nextcloud session lifetime from the server
- **AND** clear the CryptoKey when that duration elapses
