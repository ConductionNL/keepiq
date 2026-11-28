## ADDED Requirements

### Requirement: SSH key item form

The system MUST show private key, public key and fingerprint fields when the item type is SSH Key, and MUST derive the fingerprint from the public key on save. The private key MUST be stored with the secret value and the public key and fingerprint MUST be stored in the same encrypted blob. The form MUST offer Generate key pair, which creates the pair in the browser and never sends the private key anywhere before encryption, and Import from file.

#### Scenario: A user generates an SSH key pair

- **GIVEN** a vault user creating a new item of type SSH Key on the secret list
- **WHEN** the user chooses Generate key pair and saves
- **THEN** the item stores the private key encrypted, the public key and a SHA256 fingerprint, and the detail sidebar shows the public key with a Copy action

#### Scenario: A user imports an existing private key

- **GIVEN** a vault user with an OpenSSH private key file
- **WHEN** the user chooses Import from file in the SSH Key form
- **THEN** the private key and the derived public key and fingerprint are filled in before saving

#### Scenario: A malformed key is refused

- **GIVEN** a vault user in the SSH Key form
- **WHEN** the user pastes text that is not a key into the private key field and saves
- **THEN** the form shows an error on that field and nothing is stored
