---
status: proposed
---

# Ephemeral Send

## Purpose

Let a user send an ad-hoc text or credential to anyone via a link without creating a vault secret and without the recipient needing an account. The payload is encrypted client-side with a random AES-256-GCM key; that key never reaches the server (carried in the URL fragment, or additionally wrapped by an optional Argon2id password). A send burns after a configurable number of views (default 1), optionally expires (TTL), and can be revoked from a "My Sends" list. Anonymous access endpoints are rate-limited and brute-force-burned. Zero server-side plaintext or decryptable key material (ADR-003). This is the standalone delta beyond `link-sharing`, which shares only stored secrets.

## ADDED Requirements

### Requirement: Create a standalone ephemeral send

Doriath SHALL allow an authenticated user to create an ephemeral send from a typed text/credential value without creating any vault `Secret`. The payload MUST be encrypted client-side; the server MUST store only the ciphertext and parameters and MUST NOT store plaintext or any key it can use to decrypt.

#### Scenario: Create a send without a stored secret

- **GIVEN** an authenticated user types an ad-hoc value
- **WHEN** they create an ephemeral send
- **THEN** the system MUST store an `EphemeralSend` with the AES-256-GCM ciphertext, a unique ≥128-bit token, and the chosen parameters
- **AND** no vault `Secret` MUST be created
- **AND** the server MUST NOT store the payload plaintext or an unwrapped content key

#### Scenario: No-password send keeps the key out of the server

- **GIVEN** a user creates a send without setting a password
- **WHEN** the send is stored
- **THEN** the content key MUST NOT be sent to or stored by the server (it is carried in the recipient URL fragment)
- **AND** the server MUST hold only ciphertext it cannot decrypt

#### Scenario: Password send wraps the key client-side

- **GIVEN** a user creates a send and sets a password
- **WHEN** the send is stored
- **THEN** the server MUST store only the Argon2id-wrapped content key and its salt
- **AND** the server MUST NOT be able to unwrap the key without the password

### Requirement: Anonymous recipient access with no account

Doriath SHALL allow anyone with the link to retrieve the ciphertext and decrypt it client-side without authenticating. Access endpoints MUST be rate-limited and MUST NOT expose the payload plaintext to the server.

#### Scenario: Recipient decrypts a no-password send

- **GIVEN** an ephemeral send with no password and remaining views
- **WHEN** an anonymous recipient opens the link and reads the fragment key
- **THEN** the system MUST return the ciphertext and the client MUST decrypt it locally
- **AND** the server MUST NOT decrypt the payload at any point

#### Scenario: Recipient must supply the password for a password send

- **GIVEN** an ephemeral send that has a password
- **WHEN** an anonymous recipient provides the correct password
- **THEN** the client MUST unwrap the content key and decrypt locally
- **AND** the server MUST NOT learn the password or the plaintext

### Requirement: Burn-after-read and optional expiry

Doriath SHALL delete a send once its view count reaches its maximum (default 1) and MUST reject access after an optional TTL has elapsed. The maximum view count MUST be at least 1 and capped; unlimited views MUST NOT be allowed.

#### Scenario: Send burns at the view limit

- **GIVEN** an ephemeral send with `max_views` reached
- **WHEN** the token is accessed again
- **THEN** the system MUST return an error, MUST NOT return ciphertext, and MUST have deleted the send

#### Scenario: Expired send rejects access

- **GIVEN** an ephemeral send whose TTL has elapsed
- **WHEN** the token is accessed
- **THEN** the system MUST return an error and MUST NOT return ciphertext

#### Scenario: Brute-force attempts burn the send

- **GIVEN** a password-protected send
- **WHEN** 5 consecutive incorrect passwords are submitted
- **THEN** the system MUST permanently delete the send and subsequent access MUST return not-found

### Requirement: Manage and revoke sends

Doriath SHALL let a creator list their active sends and revoke any of them before it burns or expires. Only the creator SHALL see or revoke their sends.

#### Scenario: Creator lists their sends

- **GIVEN** a user has created ephemeral sends
- **WHEN** they open "My Sends"
- **THEN** the system MUST list only that user's sends with remaining views and TTL, and never another user's send

#### Scenario: Creator revokes a send

- **GIVEN** an active ephemeral send owned by the creator
- **WHEN** the creator revokes it
- **THEN** the send MUST be deleted and subsequent access to its token MUST return not-found
