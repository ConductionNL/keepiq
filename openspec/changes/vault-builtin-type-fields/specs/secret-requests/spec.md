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

#### Scenario: A fresh request files its Secret as the chosen type
@e2e exclude Service-level routing with no visual surface of its own; driven by SecretRequestServiceTest::testFreshRequestFilesThePlaceholderAsTheChosenType and ::testFreshRequestWithoutATypeLeavesTheDefault, and SecretRequestControllerTest::testCreateForwardsTheChosenTypeToTheFreshPath.
- GIVEN a user creates a fresh request and names a secret type
- WHEN the system creates the unfilled Secret
- THEN that Secret MUST have the named type, validated and subject to the team folder rule exactly as for a Secret the user creates directly
- AND without a named type the Secret MUST get the default type

#### Scenario: A fresh request is asked by type
@e2e exclude Dialog state with no server round-trip of its own; driven by the SecretRequestCreateDialog spec (type select lists the creatable types, the field list follows the type, only a required field is pre-ticked, creation needs one ticked field, the type travels with the request).
- GIVEN a user opens the dialog to ask for a new credential
- WHEN they pick a type
- THEN the requested fields MUST list that type's fields, with only the fields the type requires ticked (`builtin-type-fields`)
- AND the request MUST NOT be creatable until at least one field is ticked
- AND the chosen type MUST be sent with the request

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

### Requirement: Item Types That Cannot Be Requested
The system MUST refuse any SecretRequest for a payment card, an identity, an authenticator (TOTP) key or a passkey, whether it is a fresh request asked as one of those types or a fill-in or re-request on an existing Secret of one. Asking someone for their card or identity details through a fill link has no legitimate use this flow needs to serve; it is what fraud looks like. An authenticator key is the seed of that person's one-time codes, and a passkey holds the private key of that person's credential, so whoever is handed either can sign in as them; a one-time code itself expires within a minute, before a requester could use it. A holder MUST still be able to store, edit and share these types themselves: only requesting them is refused. The interface MUST NOT offer a request for them, and the server MUST refuse one regardless of the interface: also when the Secret's type is changed to one of them after the request was made, so opening or filling the link MUST be refused then. The rule is about the Secret's type: custom fields on another type that happen to be named like card or identity fields are out of its scope.

#### Scenario: A fresh request asked as a type that cannot be requested
@e2e exclude Server-side refusal with no visual surface beyond the type list; driven by SecretRequestPolicyTest (the four types refused, other types and the default allowed) and SecretRequestServiceTest::testFreshRequestRefusesANotRequestableTypeWithoutAPlaceholder.
- GIVEN a user creates a fresh request naming the payment card, identity, authenticator or passkey type
- WHEN the request is submitted
- THEN the system MUST refuse it with an authorization error
- AND MUST NOT create a placeholder Secret

#### Scenario: A fill-in or re-request on an existing Secret of such a type
@e2e exclude Server-side refusal; driven by SecretRequestServiceTest::testRequestOnACardSecretIsRefused, and the sidebar spec for the hidden request action.
- GIVEN a user owns a Secret of the payment card, identity, authenticator or passkey type
- WHEN they ask someone to fill it in, or ask for new values
- THEN the system MUST refuse the request with an authorization error
- AND the Secret's detail view MUST NOT offer either action

#### Scenario: The Secret's type changes after the request was made
@e2e exclude Server-side refusal; driven by SecretRequestServiceTest::testALinkIsRefusedOnceItsSecretBecameACard and ::testAFillIsRefusedOnceItsSecretBecameACard.
- GIVEN a pending request on a Login Secret
- WHEN its owner changes the Secret to the payment card type
- THEN opening the fill link MUST be refused with an authorization error
- AND a fill MUST be refused with nothing written, leaving the request pending

#### Scenario: SSH keys stay requestable
@e2e exclude Server-side rule; driven by SecretRequestPolicyTest::testOtherTypesAndTheDefaultCanBeRequested.
- GIVEN a user needs a vendor's SSH key to reach a system the vendor runs
- WHEN they create a request asked as the SSH Key type
- THEN the system MUST accept it, so the key travels encrypted instead of in a plain email
