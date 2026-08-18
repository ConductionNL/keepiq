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

### Requirement: Optional Expiry
The requester MAY set an `expires_at` when creating a SecretRequest. If set and the expiry has passed, the fill-in link MUST return an error and no submission is accepted. If not set, the request remains open until fulfilled or manually revoked.

The client MAY pre-fill a suggested expiry when a request is created, provided the requester can change or clear it. A pre-filled value is still the requester setting one, so an unexpiring request remains available to anyone who wants it.

An expiry MUST additionally be acted on rather than only checked on access. The system MUST run a background job that transitions a `pending` request whose `expires_at` has passed to the terminal status `expired`, invalidating its token and deleting the unfilled Secret it created — the same cleanup a revoke performs, for the same reason: no keyless Secret may outlive the request that justified it.

A request with NO `expires_at` MUST NOT be touched by that job. It remains open until fulfilled or manually revoked, exactly as this requirement's second sentence states.

Automatic expiry MUST be attributable in the audit trail to the system rather than to the requester, who took no action.

The access-time check MUST evaluate `expires_at` itself, independently of the stored status, and MUST NOT depend on the job having run. The job runs on an interval, so between a request lapsing and the next sweep its status still reads `pending`; a gate trusting status alone would accept a submission after the expiry the requester set. The job is cleanup, never the enforcement mechanism.

The gate MUST also handle the terminal `expired` status explicitly. Falling through to an unknown-state error would answer a legitimately expired link with a server error instead of telling the recipient the link has expired.

#### Scenario: Expired request accessed
- GIVEN a SecretRequest with an `expires_at` in the past
- WHEN the fill-in link is accessed
- THEN the system MUST return an error and MUST NOT accept a submission

#### Scenario: A lapsed request is refused before the job has swept it
- GIVEN a pending SecretRequest whose `expires_at` passed a minute ago and the expiry job has not yet run
- WHEN the fill-in link is opened
- THEN the system MUST refuse the submission on the strength of `expires_at` alone

#### Scenario: An expired request reports itself as expired, not as an error
- GIVEN a SecretRequest already transitioned to `expired`
- WHEN the fill-in link is opened
- THEN the recipient MUST be told the request has expired
- AND the system MUST NOT answer with an unknown-state or server error

#### Scenario: Expiry is acted on without anyone opening the link
- GIVEN a pending SecretRequest whose `expires_at` has passed and whose link nobody opened
- WHEN the expiry job runs
- THEN the request MUST become `expired`, its token MUST be invalidated, and the unfilled Secret it created MUST be deleted

#### Scenario: A request without an expiry is left alone
- GIVEN a pending SecretRequest with no `expires_at`
- WHEN the expiry job runs
- THEN the request MUST remain `pending` and its Secret MUST NOT be deleted

#### Scenario: An expired re-request preserves the existing values
- GIVEN a pending re-request against a filled Secret, whose `expires_at` has passed
- WHEN the expiry job runs
- THEN the request MUST become `expired` and the existing Secret and its current values MUST be preserved

#### Scenario: A suggested expiry can be cleared
- GIVEN the create surface pre-filled a suggested expiry
- WHEN the requester clears it before submitting
- THEN the request MUST be created with no `expires_at`

### Requirement: Revoke Request
The requester MUST be able to revoke a pending SecretRequest before it is fulfilled.

Revocation MAY also be performed by the system when a request expires, per the Optional Expiry requirement. The two MUST be distinguishable: a requester-initiated revocation and an automatic expiry MUST NOT be recorded as the same terminal state, so that a vault row disappearing can always be explained.

#### Scenario: Revoke pending request (new secret)
- GIVEN a SecretRequest with status `pending` that created a new unfilled Secret
- WHEN the requester revokes it
- THEN the token MUST be invalidated and the unfilled Secret MUST be deleted

#### Scenario: Revoke pending re-request
- GIVEN a re-request with status `pending` (existing Secret with current values)
- WHEN the requester revokes it
- THEN the token MUST be invalidated
- AND the existing Secret and its current values MUST be preserved

#### Scenario: An expiry is not reported as a cancellation
- GIVEN a request that lapsed because its expiry passed
- WHEN the requester inspects what happened to it
- THEN it MUST be distinguishable from a request they revoked themselves


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

### Requirement: Outstanding Request Indicator

A Secret with a pending SecretRequest against it MUST be visibly marked as awaiting a fill wherever secrets are listed. An unfilled placeholder is indistinguishable from a broken or empty Secret without it, and this change makes such placeholders the normal result of asking someone for a credential rather than a rare edge case.

The indicator MUST distinguish a Secret that is waiting for its first values from one that already holds values and has a re-request outstanding, because the consequences differ: the first cannot be used yet, the second is usable until the new values arrive.

The indicator MUST NOT expose the fill token. Anyone who can see the vault listing can already retrieve the token through the request itself; putting it in a list row spreads a credential-bearing URL into screenshots and shoulder-surfing range for no benefit.

#### Scenario: A placeholder awaiting its first fill
- **GIVEN** a Secret created by a fresh request whose values have not been submitted
- **WHEN** the owner views their secret list
- **THEN** the Secret MUST be marked as awaiting a fill rather than appearing to be an ordinary empty Secret

#### Scenario: A filled Secret with a re-request outstanding
- **WHEN** the owner views a Secret that holds values and has a pending re-request
- **THEN** it MUST be marked as having a request outstanding, and MUST remain distinguishable from a Secret that has no values yet

#### Scenario: The indicator clears when the request ends
- **GIVEN** a Secret marked as awaiting a fill
- **WHEN** the request is fulfilled, revoked or expires
- **THEN** the marking MUST no longer be shown

#### Scenario: The listing never carries the token
- **WHEN** a Secret with a pending request is listed
- **THEN** the response MUST NOT include the request's fill token
