---
status: proposed
---

# Passkey Vault Login

## Purpose

Give Doriath users a passwordless way to unlock their vault using a WebAuthn
authenticator's PRF extension, as an alternative unlock envelope stored
alongside the canonical master-password envelope — without weakening ADR-003's
always-E2E, zero-knowledge guarantee, and always leaving the master password as
an available fallback.

## ADDED Requirements

### Requirement: Passkey enrollment requires an unlocked vault and produces a client-side envelope

Doriath SHALL let a user enroll a WebAuthn authenticator for vault unlock only
while their vault is unlocked, and SHALL derive a key-encryption key from the
authenticator's PRF output client-side and store only a wrapped copy of the vault
unlock key — never the master password, the unlock key in the clear, or the PRF
output.

#### Scenario: Enrollment while unlocked stores a wrapped envelope

- **GIVEN** a user with an unlocked vault and a PRF-capable authenticator
- **WHEN** they enroll a passkey
- **THEN** the browser MUST obtain the PRF output, derive a KEK via HKDF-SHA256,
  AES-256-GCM wrap the in-memory vault unlock key, and POST only the credential
  metadata and the wrapped-key envelope
- **AND** the server MUST persist a `doriath_passkey_credentials` record with
  status `active` and MUST NOT receive the master password, the plaintext unlock
  key, or the raw PRF output

#### Scenario: Enrollment is refused when the vault is locked

- **GIVEN** a user whose vault is locked (no unlock key in memory)
- **WHEN** they attempt to enroll a passkey
- **THEN** the enrollment MUST be refused because the wrap cannot be produced
  without the plaintext unlock key
- **AND** no credential record MUST be created

### Requirement: Passwordless unlock via PRF derives the unlock key client-side

Doriath SHALL offer a "Unlock with passkey" action on the lock screen when a
passkey is enrolled and the browser supports PRF, and SHALL open the vault by
deriving the KEK from a fresh assertion's PRF output, unwrapping the vault unlock
key, and importing the private key as a non-extractable `CryptoKey` — with the
master password never entered and never sent to the server.

#### Scenario: Passkey unlock opens the vault

- **GIVEN** a user with an enrolled, `active` passkey and a locked vault
- **WHEN** they choose "Unlock with passkey" and complete the authenticator
  ceremony
- **THEN** the browser MUST derive the KEK from the PRF output, AES-256-GCM
  decrypt the wrapped unlock key, decrypt the existing private-key blob with it,
  and import a non-extractable `CryptoKey`
- **AND** the resulting unlocked state MUST be identical to a master-password
  unlock, with no master password entered or transmitted

#### Scenario: Failed authenticator ceremony leaves the vault locked

- **GIVEN** a user attempting passkey unlock
- **WHEN** the authenticator ceremony is cancelled or the PRF output does not
  decrypt the envelope
- **THEN** the vault MUST remain locked
- **AND** the lock screen MUST still offer the master-password path

### Requirement: The master password remains the canonical fallback

Doriath SHALL always keep the master-password unlock envelope intact and
available, and losing or removing an authenticator SHALL NOT cause any loss of
vault data or access.

#### Scenario: Master-password unlock still works after enrolling a passkey

- **GIVEN** a user who has enrolled a passkey
- **WHEN** they choose "Use master password" and enter it correctly
- **THEN** the vault MUST unlock exactly as before the passkey existed

#### Scenario: Lost authenticator loses no data

- **GIVEN** a user whose only enrolled authenticator is lost or reset
- **WHEN** they open the lock screen
- **THEN** they MUST be able to unlock with their master password
- **AND** no secret MUST be inaccessible as a result of the lost authenticator

### Requirement: Users can manage and revoke enrolled passkeys

Doriath SHALL let a user list their enrolled passkeys and revoke any of them,
and revocation SHALL delete the server-side envelope so the revoked authenticator
can no longer unlock the vault. All passkey endpoints SHALL be authenticated and
scoped to the calling user's own credentials.

#### Scenario: Revocation deletes the unlock envelope

- **GIVEN** a user with an `active` enrolled passkey
- **WHEN** they revoke it
- **THEN** the server MUST delete (or mark `revoked` and purge the envelope for)
  that credential
- **AND** a subsequent "Unlock with passkey" attempt with that authenticator MUST
  NOT be offered nor succeed

#### Scenario: A user cannot act on another user's passkeys

- **GIVEN** two users each with enrolled passkeys
- **WHEN** one user calls a passkey endpoint with another user's credential id
- **THEN** the request MUST be rejected as forbidden and MUST NOT read, use, or
  delete the other user's credential

### Requirement: PRF support is feature-detected and degrades without blocking

Doriath SHALL feature-detect WebAuthn PRF availability at runtime and SHALL hide
the passkey option where unsupported, never preventing the master-password unlock
path from working.

#### Scenario: Unsupported browser shows only the master-password path

- **GIVEN** a browser without `PublicKeyCredential` or an authenticator that does
  not enable PRF
- **WHEN** the lock screen or enrollment UI loads
- **THEN** the passkey option MUST NOT be offered
- **AND** the master-password unlock MUST be fully available

### Requirement: Passkey envelopes are invalidated when the unlock key changes

Doriath SHALL mark a passkey envelope stale when a routine master-password change
re-wraps the private key under a new unlock key, and SHALL delete all of an
owner's passkey envelopes when a compromise-recovery suite rotation generates a
new RSA key pair.

#### Scenario: Routine master-password change marks passkeys stale

- **GIVEN** a user with an `active` passkey wrapped for the current unlock-key epoch
- **WHEN** the user completes a routine master-password change (new unlock key)
- **THEN** the passkey envelope MUST be treated as `stale` (epoch mismatch)
- **AND** a passkey unlock attempt MUST fall back to the master password and
  prompt the user to re-enroll

#### Scenario: Compromise recovery deletes all passkey envelopes

- **GIVEN** a user with one or more enrolled passkeys
- **WHEN** compromise recovery rotates the user's EncryptionSuite to a new RSA key
  pair
- **THEN** all of that user's passkey envelopes MUST be deleted
- **AND** the user MUST re-enroll any passkey against the new suite before
  passwordless unlock is available again
