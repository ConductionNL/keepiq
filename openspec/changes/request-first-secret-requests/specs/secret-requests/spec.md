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
@e2e exclude Service-level creation with no UI state of its own; driven by SecretRequestServiceTest::testFreshRequestCreatesItsOwnPlaceholder (placeholder created keyless with the explicit opt-in, suite read off the created Secret) and the dialog spec "submits a FRESH request with no secret prop". Verified live: POST with only requestedFields + name answers 201 and the row lands with an EMPTY key.
- GIVEN a user has an active EncryptionSuite and no Secret prepared for the credential
- WHEN they create a SecretRequest supplying only the requested fields, and optionally a name, folder and expiry
- THEN the system MUST create the unfilled Secret itself and link the request to it
- AND the system MUST NOT require a key, or any other credential value, from the requester
- AND the created Secret MUST be owned by the requester and placed in the supplied folder when one was given

#### Scenario: A fresh request creates its own Secret rather than reusing one
@e2e exclude Two-call invariant with no visual surface; driven by SecretRequestServiceTest::testTwoFreshRequestsDoNotShareAPlaceholder.
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
@e2e exclude Pre-selection is client state, asserted directly rather than through a browser; driven by the dialog spec "does not pre-select a field that already holds a value" and "detects filled additional-field members from the decrypted blob".
- **GIVEN** a Secret whose `login` already holds a value and whose `key` does not
- **WHEN** the requester creates a fresh request against it
- **THEN** `login` MUST NOT be pre-selected
- **AND** the requester MUST be able to select it anyway if they intend to replace it

#### Scenario: Filled-ness is never disclosed to the recipient
@e2e exclude An assertion about a JSON payload, not a rendered page; driven by SecretRequestFillControllerTest::testShowNeverDisclosesWhichFieldsAreAlreadyFilled, which asserts the response as a CLOSED set so a future field cannot leak in unnoticed.
- **GIVEN** a fresh request was created against a Secret with existing values
- **WHEN** the recipient opens the fill-in link
- **THEN** the response MUST NOT indicate which fields of the Secret already hold values
- **AND** the recipient MUST be prompted for exactly the requested fields, with no indication of what was excluded

#### Scenario: A re-request still offers the filled fields
@e2e exclude Client selection state; driven by the dialog spec "a re-request still pre-selects the filled key, because replacing is the point".
- **GIVEN** a fully-filled Secret due for credential rotation
- **WHEN** the requester creates a re-request
- **THEN** the already-filled fields MUST remain selectable, since replacing them is the purpose of a re-request

#### Scenario: A fresh request on a new Secret has nothing to exclude
@e2e exclude Vacuous by construction once the Secret is created empty; driven by the dialog spec "submits a FRESH request with no secret prop", which submits with no target and therefore no filled fields.
- **GIVEN** a fresh request that creates its own unfilled Secret
- **WHEN** the requested fields are determined
- **THEN** no field is excluded, because the Secret holds no values

### Requirement: Outstanding Request Indicator

A Secret with a pending SecretRequest against it MUST be visibly marked as awaiting a fill wherever secrets are listed. An unfilled placeholder is indistinguishable from a broken or empty Secret without it, and this change makes such placeholders the normal result of asking someone for a credential rather than a rare edge case.

The indicator MUST distinguish a Secret that is waiting for its first values from one that already holds values and has a re-request outstanding, because the consequences differ: the first cannot be used yet, the second is usable until the new values arrive.

The indicator MUST NOT expose the fill token. Anyone who can see the vault listing can already retrieve the token through the request itself; putting it in a list row spreads a credential-bearing URL into screenshots and shoulder-surfing range for no benefit.

#### Scenario: A placeholder awaiting its first fill
@e2e exclude Row rendering asserted at component level; driven by SecretListItem.spec.js "marks a placeholder as awaiting its first fill".
- **GIVEN** a Secret created by a fresh request whose values have not been submitted
- **WHEN** the owner views their secret list
- **THEN** the Secret MUST be marked as awaiting a fill rather than appearing to be an ordinary empty Secret

#### Scenario: A filled Secret with a re-request outstanding
@e2e exclude Row rendering asserted at component level; driven by SecretListItem.spec.js "distinguishes a re-request from an empty placeholder".
- **WHEN** the owner views a Secret that holds values and has a pending re-request
- **THEN** it MUST be marked as having a request outstanding, and MUST remain distinguishable from a Secret that has no values yet

#### Scenario: The indicator clears when the request ends
@e2e exclude Driven by SecretListItem.spec.js "shows no indicator when there is no outstanding request"; the clearing itself is SecretList.requestStateFor() returning null once the request is no longer pending, which the badge is bound to.
- **GIVEN** a Secret marked as awaiting a fill
- **WHEN** the request is fulfilled, revoked or expires
- **THEN** the marking MUST no longer be shown

#### Scenario: The listing never carries the token
@e2e exclude Structural: the row component takes a STATE, never the request, so a token has no path into it. Driven by SecretListItem.spec.js "never renders a fill token in the row", which asserts no 32-hex string appears in the rendered HTML.
- **WHEN** a Secret with a pending request is listed
- **THEN** the response MUST NOT include the request's fill token
