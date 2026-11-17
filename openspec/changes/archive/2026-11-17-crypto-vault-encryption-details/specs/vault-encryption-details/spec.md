## ADDED Requirements

### Requirement: The user sees what protects the vault

The Encryption section of the user settings MUST state, in plain words with the technical names alongside: the algorithm, hash and key size that encrypt secret values; the algorithm and key derivation, with its iteration count, that protect the private key with the master password; how attachments are encrypted; and the key derivation and cipher that protect encrypted backups, link shares and sends. Every value shown MUST come from the parameters the crypto code exports, not from separate text.

#### Scenario: A security officer fills in a supplier questionnaire

- **GIVEN** a vault user with an active encryption suite
- **WHEN** the user opens the user settings and the Encryption section
- **THEN** the section says secret values are encrypted with RSA-OAEP, SHA-256, 4096-bit keys
- **AND** it says the private key is protected with AES-256-GCM under a key derived with PBKDF2-SHA256 at 600,000 iterations
- **AND** it names Argon2id with its memory, iterations and parallelism for backups and link shares

#### Scenario: The screen follows the code

- **GIVEN** a developer who raises the PBKDF2 iteration count in the crypto code
- **WHEN** the web app is rebuilt
- **THEN** the Encryption section shows the new count without any other edit

### Requirement: The vault certificate shows its key and signature algorithm

The certificate inventory MUST include the key type, key size and signature algorithm of each vault certificate, read by the server from the certificate. The certificates page MUST show them next to the subject and expiry. No private key material MUST be read or shown.

#### Scenario: A vault user inspects their certificate

- **GIVEN** a vault user on the certificates page at /certificates
- **WHEN** the vault encryption certificates table renders
- **THEN** the user's certificate row shows key type RSA, key size 4096 and its signature algorithm
