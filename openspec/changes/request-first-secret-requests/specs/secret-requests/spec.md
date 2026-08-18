## MODIFIED Requirements

### Requirement: Create Secret Request
The system MUST allow an authenticated user or application to create a SecretRequest, subject to the following ownership rules:

- A user MAY create a SecretRequest for their own secrets
- A user MAY create a SecretRequest for an application's secrets
- A user MUST NOT create a SecretRequest targeting another user's secret

A FRESH request MUST NOT require the requester to name an existing Secret. The requester supplies the fields they want, and optionally a name, folder and expiry; the SYSTEM creates the unfilled Secret. The requester MUST NOT be required to supply a value for the credential being requested — asking someone for a credential cannot presuppose knowing it. This was previously stated only in the Purpose section and in the acceptance criteria, which is how an implementation requiring a pre-existing Secret passed every scenario.

A RE-REQUEST is the only creation path that targets an existing Secret, per the Re-request requirement. `secret_id` is therefore mandatory for a re-request and optional for a fresh request.

#### Scenario: Create request for own secret
- GIVEN a user has an active EncryptionSuite
- WHEN they create a SecretRequest specifying which fields to request
- THEN the system MUST create an unfilled Secret and a SecretRequest with a unique token
- AND return the fill-in link to the requester

#### Scenario: Create a fresh request without naming an existing Secret
- GIVEN a user has an active EncryptionSuite and no Secret prepared for the credential
- WHEN they create a SecretRequest supplying only the requested fields, and optionally a name, folder and expiry
- THEN the system MUST create the unfilled Secret itself and link the request to it
- AND the system MUST NOT require a key, or any other credential value, from the requester
- AND the created Secret MUST be owned by the requester and placed in the supplied folder when one was given

#### Scenario: A fresh request creates its own Secret rather than reusing one
- GIVEN a user creates two fresh requests in succession
- WHEN both are created
- THEN each MUST have its own unfilled Secret, and neither MUST write into a Secret created for the other

#### Scenario: Create request for application secret
- GIVEN a user has an active EncryptionSuite and an application exists with an active EncryptionSuite
- WHEN the user creates a SecretRequest targeting the application's vault
- THEN the system MUST create an unfilled Secret owned by the application and a SecretRequest
- AND the submitted values MUST be encrypted with the application's public certificate

#### Scenario: Attempt to create request for another user's secret
- GIVEN user A attempts to create a SecretRequest targeting user B's secret
- WHEN the request is submitted
- THEN the system MUST return an authorization error

## ADDED Requirements

### Requirement: Fresh Requests Do Not Re-ask for Values That Already Exist

A fresh request MUST NOT pre-select a field that already holds a value on the target Secret. The requester MAY still select such a field deliberately — re-asking is legitimate — but it MUST be a choice rather than a default.

This matters because a recipient cannot decline: per the Field Validation requirement every field named in `requested_fields` MUST be submitted with a non-empty value. A filled field carried into a fresh request therefore compels an overwrite of a good value rather than merely inviting one.

The determination MUST be made when the request is created, by the requester's client, and MUST NOT be exposed to the fill recipient. Two constraints force this:

- Telling the fill endpoint's caller which fields already hold values would hand vault metadata about a credential to an unauthenticated party. The fill surface preserves write-without-read, and "this field already has a value" is a read.
- The server cannot determine it for additional fields at all: it never decrypts the `additional_fields` blob (ADR-003), so it can see only that a blob exists. Per-member completeness is a client-side concern, as the Requestable Fields requirement already states. A server-side filter would silently cover the reserved columns and miss named extras.

A RE-REQUEST is exempt: overwriting existing values in place is that flow's stated purpose, so filled fields remain selectable there without being deprioritised.

#### Scenario: A fresh request against a partly-filled Secret
- **GIVEN** a Secret whose `login` already holds a value and whose `key` does not
- **WHEN** the requester creates a fresh request against it
- **THEN** `login` MUST NOT be pre-selected
- **AND** the requester MUST be able to select it anyway if they intend to replace it

#### Scenario: Filled-ness is never disclosed to the recipient
- **GIVEN** a fresh request was created against a Secret with existing values
- **WHEN** the recipient opens the fill-in link
- **THEN** the response MUST NOT indicate which fields of the Secret already hold values
- **AND** the recipient MUST be prompted for exactly the requested fields, with no indication of what was excluded

#### Scenario: A re-request still offers the filled fields
- **GIVEN** a fully-filled Secret due for credential rotation
- **WHEN** the requester creates a re-request
- **THEN** the already-filled fields MUST remain selectable, since replacing them is the purpose of a re-request

#### Scenario: A fresh request on a new Secret has nothing to exclude
- **GIVEN** a fresh request that creates its own unfilled Secret
- **WHEN** the requested fields are determined
- **THEN** no field is excluded, because the Secret holds no values
