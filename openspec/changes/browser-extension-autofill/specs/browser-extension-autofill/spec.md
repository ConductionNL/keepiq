---
status: proposed
---

# Browser Extension + Autofill

## Purpose

Give Doriath a browser extension that fills credentials into login forms while preserving zero-knowledge: the extension pairs against the Nextcloud session, unlocks the vault **in the extension** (client-side decrypt), lists URL-matched credentials, autofills login forms including iframes, prompts to save/update on submit, and auto-locks on idle. The server never sees the master password, the derived key, or plaintext (ADR-003).

## ADDED Requirements

### Requirement: Extension pairs against the Nextcloud session with a natively-revocable credential

Doriath SHALL allow a browser extension to authenticate to the API using a Nextcloud-native credential (an app password or an OAuth-style authorization flow) tied to the logged-in user. Doriath SHALL NOT mint a new long-lived Doriath-specific credential for pairing, so revocation remains native to Nextcloud.

#### Scenario: Extension pairs with an app password

- **GIVEN** a user logged into Nextcloud who creates a device credential for the extension
- **WHEN** the extension authenticates to the Doriath API with that credential
- **THEN** the API MUST accept the request through the existing authenticated middleware and MUST NOT create a new long-lived Doriath credential

#### Scenario: Pairing is revoked from Nextcloud

- **GIVEN** a paired extension using a Nextcloud app password
- **WHEN** the user revokes that app password in Nextcloud security settings
- **THEN** the extension's subsequent API requests MUST be rejected without any Doriath admin action

### Requirement: Vault unlock happens in the extension, preserving zero-knowledge

Doriath SHALL require the master password to be entered in the extension and used only client-side to derive the AES key, decrypt the private key, and import a non-extractable WebCrypto key held in the extension's memory. The master password, the derived key, and plaintext secret values SHALL NEVER be transmitted to the server.

#### Scenario: Unlock decrypts client-side only

- **GIVEN** a paired extension
- **WHEN** the user enters the master password to unlock
- **THEN** the extension MUST fetch the encrypted private-key blob, derive the AES key and decrypt the private key entirely client-side, and import a non-extractable `CryptoKey`
- **AND** the master password and derived key MUST NOT be sent to the server

#### Scenario: Paired but locked extension cannot decrypt

- **GIVEN** a paired extension that has not been unlocked
- **WHEN** it requests secret data
- **THEN** the server MUST return only encrypted blobs and the extension MUST NOT be able to decrypt any value until the master password is entered

#### Scenario: Key is never persisted to extension storage

- **GIVEN** an unlocked extension holding a `CryptoKey`
- **WHEN** the extension stores configuration
- **THEN** the `CryptoKey`, the master password, and any plaintext MUST NOT be written to `storage.local`, `storage.sync`, or any persistent store

### Requirement: URL-matched credential listing with decrypt-on-demand

Doriath's extension SHALL offer credentials matching the active tab's origin using the unencrypted `url`/`name` fields, and SHALL decrypt a secret's value client-side only when the user selects it to fill.

#### Scenario: Matching credentials are offered for the active origin

- **GIVEN** an unlocked extension and an active tab at a given origin
- **WHEN** stored secrets have `url`/`name` matching that origin
- **THEN** the extension MUST list those secrets as fill candidates without decrypting their values until one is chosen

#### Scenario: No match offers nothing

- **GIVEN** an active tab whose origin matches no stored secret
- **WHEN** the extension evaluates candidates
- **THEN** the extension MUST offer no autofill candidate for that origin

### Requirement: Autofill into login forms including iframes

Doriath's extension SHALL fill the selected credential into detected username/password fields on the page, including fields inside iframes it is permitted to script. Where a cross-origin iframe cannot be scripted, the extension SHALL degrade to a manual-copy option rather than fail silently.

#### Scenario: Fill a login form in an iframe

- **GIVEN** an unlocked extension and a login form rendered inside a scriptable iframe
- **WHEN** the user chooses a matching credential to fill
- **THEN** the username and password fields inside the iframe MUST be populated

#### Scenario: Non-scriptable frame degrades gracefully

- **GIVEN** a login form inside a cross-origin iframe the extension cannot script
- **WHEN** the user requests autofill
- **THEN** the extension MUST surface the credential for manual copy rather than silently doing nothing

### Requirement: Save/update capture on form submit

Doriath's extension SHALL detect credentials entered into a login form on submit and offer to save a new secret or update an existing one, encrypting the value client-side before sending only a blob to the server.

#### Scenario: Offer to save a new credential

- **GIVEN** a login form submitted with credentials not matching any stored secret for the origin
- **WHEN** the form is submitted
- **THEN** the extension MUST offer to save the credential, and on confirmation MUST encrypt the value client-side and POST only the encrypted blob

#### Scenario: Offer to update a changed credential

- **GIVEN** a stored secret for the origin whose password differs from the one just submitted
- **WHEN** the form is submitted
- **THEN** the extension MUST offer to update the stored secret with a client-side-encrypted blob

### Requirement: Extension auto-locks

Doriath's extension SHALL clear the in-memory key on a configurable idle timeout, on browser/OS lock or service-worker termination, and on a manual lock action, requiring re-unlock before any further decryption.

#### Scenario: Idle timeout locks the extension

- **GIVEN** an unlocked extension left idle beyond its configured timeout
- **WHEN** the timeout elapses
- **THEN** the in-memory key MUST be cleared and the next decrypt attempt MUST require re-entering the master password

#### Scenario: Manual lock clears the key immediately

- **GIVEN** an unlocked extension
- **WHEN** the user chooses "Lock"
- **THEN** the in-memory key MUST be cleared immediately
