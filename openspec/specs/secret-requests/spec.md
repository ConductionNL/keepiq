# Secret Requests Specification

**Status**: in-progress

**OpenSpec changes:**
- `implement-secret-requests` (2026-03-31) — Full implementation: fill-in links, write-without-read, re-requests, expiry, revocation, notifications
- `request-first-secret-requests` (2026-08-18) — Brings the human flow into line with a MUST it was violating: a fresh request creates its OWN unfilled Secret instead of requiring a pre-existing one with an invented key; re-request stays the only path targeting an existing Secret; a fresh request no longer pre-selects fields that already hold values, decided client-side at creation and never disclosed to the fill recipient
- `secret-request-expiry-lifecycle` (2026-08-18) — Makes expiry act rather than only be checked: a suggested expiry is pre-filled and clearable (nothing populated `expires_at` before, so almost nothing expired), a TimedJob sweeps lapsed pending requests to a new terminal `expired` status deleting a fresh request's placeholder while preserving a re-request's values, automatic expiry is attributed to the system, and the access check evaluates `expires_at` independently of stored status — which buys precedence (a lapsed request reports expiry even when another status would also refuse) and safety against a status added later, NOT the closing of a live hole: the pending branch already checked expiry. Prioritised below `request-first-secret-requests` for the beta

## Purpose

@e2e exclude No secret-request UI is built in v0.1; all scenarios exercise fill-in-link crypto flows and write-without-read API semantics — covered by integration tests, not Playwright UI flows.

A user or application can request that a secret be filled in by an external party. Doriath creates an unfilled Secret (a placeholder with no key value) and generates a fill-in link. Anyone with the link can submit the secret values; the data is encrypted immediately on receipt using the requester's public certificate.

Critically, the requester cannot read the submitted values after they have been stored. This is by design: it prevents the requester from becoming a point of leakage (e.g., an administrator requesting application secrets from a vendor — the vendor fills them in, and the administrator cannot see them).

## Data Model

### SecretRequest

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `secret_id` | FK | No | The unfilled Secret this request writes to |
| `encryption_suite_id` | FK | No | The EncryptionSuite whose public certificate is used to encrypt submitted values. Updated to the new suite during compromise recovery migration. |
| `token` | string | No | URL-safe random token with at least 128 bits of entropy (generated via `random_bytes()`) |
| `requested_fields` | JSON | No | Array of field names the requester is asking for |
| `status` | enum | No | `pending`, `locked`, `fulfilled` — `locked` is set during the requester's compromise recovery migration; fill-in link returns "temporarily unavailable" while locked |
| `expires_at` | datetime | No | Optional expiry set by the requester at creation time; null = no expiry |
| `created_at` | datetime | No | |
| `fulfilled_at` | datetime | No | |

The Secret linked to a SecretRequest starts with all sensitive fields empty. When the fill-in link is used, the submitted values are encrypted with the requester's public certificate and stored in the Secret.
## Requirements
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

### Requirement: Requestable Fields

A `SecretRequest` MUST be able to request every field a Secret supports, and for the encrypted extras it MUST be able to name which ones it wants.

`requested_fields` entries are field names. Three are reserved and map to the Secret's own columns; every other name is an **additional field**, carried inside the single encrypted `additional_fields` blob:

| Requested name | Stored in | Nature |
|---|---|---|
| `key` | `key` | RSA ciphertext |
| `login` | `login` | RSA ciphertext |
| `url` | `url` | **plaintext metadata** (searchable, per the secrets spec) |
| any other name | a member of the `additional_fields` JSON object | RSA ciphertext (whole blob) |

Because `url` is plaintext by design and the rest is ciphertext, a fill submission MUST carry them separately: encrypted values under `encryptedFields` and plaintext metadata under `plainFields`. A client MUST NOT encrypt a plaintext metadata field — storing ciphertext in a searchable column would break search and render the value unusable — and the system MUST refuse a field submitted in the wrong one.

`additional_fields` remains ONE encrypted blob. The requested member names live on the request, which is plaintext non-sensitive metadata; the member names are NOT stored on the Secret. This deliberately keeps the encryption boundary where the secrets, secret-import and cxf-import-export specs put it: only `name`, `url`, type and folder path may be plaintext on a Secret.

The consequence MUST be stated rather than implied: the system CANNOT verify that the requested additional members were actually filled, because it never decrypts the blob (ADR-003). It can only confirm that a blob arrived. Per-member completeness is a client-side concern.

#### Scenario: A request names top-level and additional fields together
- **GIVEN** a requester creates a request for `key`, `url` and `api-interface-id`
- **WHEN** the fill-in link is opened
- **THEN** the recipient MUST be prompted for all three
- **AND** `key` MUST be submitted as ciphertext, `url` as plaintext metadata, and `api-interface-id` as a member of the encrypted `additional_fields` blob

#### Scenario: A plaintext metadata field is submitted as ciphertext
- **GIVEN** a request asking for `url`
- **WHEN** the submission carries `url` under `encryptedFields` instead of `plainFields`
- **THEN** the system MUST refuse it rather than storing ciphertext in a searchable column

#### Scenario: Additional members cannot be verified server-side
- **GIVEN** a request asking for the additional members `api-key` and `api-interface-id`
- **WHEN** an encrypted `additional_fields` blob is submitted
- **THEN** the system MUST accept the blob as satisfying both
- **AND** MUST NOT claim to have verified their presence, which would require decrypting it

### Requirement: Fill In via Link
Anyone with the fill-in link MUST be able to submit values for the requested fields without authentication.

#### Scenario: Fill in request
- GIVEN a SecretRequest with status `pending`
- WHEN an external party submits values via the token link
- THEN the system MUST encrypt the submitted values with the requester's public certificate
- AND store them in the linked Secret
- AND set the SecretRequest status to `fulfilled`

#### Scenario: Requested field names are limited to the Secret's value fields

- GIVEN `requested_fields` is free-form JSON and can therefore name anything
- WHEN a submitted field name is not one of `key`, `login` or `additionalFields`
- THEN the system MUST refuse the submission rather than storing it nowhere
- AND the SecretRequest MUST remain `pending`

#### Scenario: A failed write leaves the request fillable

- GIVEN values have been submitted for a pending request
- WHEN storing them on the linked Secret fails
- THEN the SecretRequest MUST remain `pending` so the link can be used again
- AND it MUST NOT be reported as `fulfilled`

#### Scenario: Requester cannot read after submission
- GIVEN a SecretRequest has been fulfilled
- WHEN the requester retrieves the linked Secret
- THEN the system MUST return the secret in its normal encrypted form — the requester must provide their master password to decrypt it
- AND the fill-in link is no longer usable

### Requirement: Optional Expiry
The requester MAY set an `expires_at` when creating a SecretRequest. If set and the expiry has passed, the fill-in link MUST return an error and no submission is accepted. If not set, the request remains open until fulfilled or manually revoked.

The client MAY pre-fill a suggested expiry when a request is created, provided the requester can change or clear it. A pre-filled value is still the requester setting one, so an unexpiring request remains available to anyone who wants it.

An expiry MUST additionally be acted on rather than only checked on access. The system MUST run a background job that transitions a `pending` request whose `expires_at` has passed to the terminal status `expired`, invalidating its token and deleting the unfilled Secret it created — the same cleanup a revoke performs, for the same reason: no keyless Secret may outlive the request that justified it. A re-request's existing Secret and its current values MUST be preserved; a request lapsing MUST NOT cost the owner a working credential.

A request with NO `expires_at` MUST NOT be touched by that job. It remains open until fulfilled or manually revoked, exactly as this requirement's second sentence states.

Automatic expiry MUST be attributable in the audit trail to the system rather than to the requester, who took no action.

The access-time check MUST evaluate `expires_at` itself, independently of the stored status, and MUST NOT depend on the job having run — the job is cleanup, never the enforcement mechanism. Stated precisely, because the weaker claim is the true one: a lapsed request in `pending` was already refused, since that branch checked expiry itself. Evaluating expiry independently of status means (a) a lapsed request reports EXPIRY even when another status would also refuse it — a locked request whose expiry passed says "expired" rather than "temporarily unavailable", which is truer since locked invites a retry that can never succeed — and (b) a status added later cannot bypass expiry by omission.

The gate MUST also handle the terminal `expired` status explicitly. Falling through to an unknown-state error would answer a legitimately expired link with a server error instead of telling the recipient the link has expired.

#### Scenario: Expired request accessed
@e2e exclude Gate behaviour asserted directly; driven by SecretRequestPolicyTest::testLapsedButUnsweptRequestIsRefused.
- GIVEN a SecretRequest with an `expires_at` in the past
- WHEN the fill-in link is accessed
- THEN the system MUST return an error and MUST NOT accept a submission

#### Scenario: A lapsed request is refused before the job has swept it
@e2e exclude Same gate, the unswept case; driven by SecretRequestPolicyTest::testLapsedButUnsweptRequestIsRefused, which pins that expiry is judged on the timestamp rather than the stored status.
- GIVEN a pending SecretRequest whose `expires_at` passed a minute ago and the expiry job has not yet run
- WHEN the fill-in link is opened
- THEN the system MUST refuse the submission on the strength of `expires_at` alone

#### Scenario: An expired request reports itself as expired, not as an error
@e2e exclude Driven by SecretRequestPolicyTest::testASweptRequestReportsExpiryWith408 (the shape production holds: expired status AND a past timestamp) and ::testExpiredStatusWithoutATimestampStillReportsExpiry (the fallback arm, which is the only way to reach the switch). Both assert expiry and explicitly NOT the 500 unknown-state error. Corrected after measuring the page in a browser on 2026-08-19: this exclude previously claimed 410 and claimed the recipient's message was covered by SecretRequestFill.vue's `expired` case. Both were wrong — the reachable code is 408, and that case was unreachable, so the recipient read this server's English "Request has expired" rather than the translated string. The message is now driven by the response's `reason` field, covered by SecretRequestFillControllerTest::testShowIncludesAMachineReadableReasonOnRefusal and the view spec "shows the translatable message for the reason, not the server English".
- GIVEN a SecretRequest already transitioned to `expired`
- WHEN the fill-in link is opened
- THEN the recipient MUST be told the request has expired
- AND the system MUST NOT answer with an unknown-state or server error

#### Scenario: Expiry is acted on without anyone opening the link
@e2e exclude A background job with no UI surface; driven by SecretRequestServiceTest::testExpireDeletesThePlaceholderAndAttributesTheSystem. Verified live: running the job swept three genuinely lapsed seeded requests, recorded request.expired with actor_type=system, and deleted the placeholder.
- GIVEN a pending SecretRequest whose `expires_at` has passed and whose link nobody opened
- WHEN the expiry job runs
- THEN the request MUST become `expired`, its token MUST be invalidated, and the unfilled Secret it created MUST be deleted

#### Scenario: A request without an expiry is left alone
@e2e exclude Query-level guarantee; driven by SecretRequestPolicyTest::testARequestWithoutAnExpiryIsAllowed and the mapper's explicit `expires_at IS NOT NULL` predicate.
- GIVEN a pending SecretRequest with no `expires_at`
- WHEN the expiry job runs
- THEN the request MUST remain `pending` and its Secret MUST NOT be deleted

#### Scenario: An expired re-request preserves the existing values
@e2e exclude Driven by SecretRequestServiceTest::testExpireNeverDeletesAFilledSecret — a request lapsing must never cost the owner a working credential.
- GIVEN a pending re-request against a filled Secret, whose `expires_at` has passed
- WHEN the expiry job runs
- THEN the request MUST become `expired` and the existing Secret and its current values MUST be preserved

#### Scenario: A suggested expiry can be cleared
@e2e exclude Client state; driven by the dialog spec "the suggestion can be cleared, so a perpetual request stays one action away", which asserts the submitted expiresAt is null.
- GIVEN the create surface pre-filled a suggested expiry
- WHEN the requester clears it before submitting
- THEN the request MUST be created with no `expires_at`

### Requirement: Notification on Fulfillment
When a SecretRequest is fulfilled, the requester MUST receive a Nextcloud notification.

Notification content:
- Body: "Your secret request has been fulfilled"
- Action link: opens the linked Secret in the requester's vault

#### Scenario: Request fulfilled
- GIVEN a SecretRequest with status `pending`
- WHEN an external party successfully submits values
- THEN the requester MUST receive a Nextcloud notification

### Requirement: Field Validation
All fields listed in `requested_fields` MUST be submitted with a non-empty value. The system MUST reject submissions where any requested field is empty or missing.

#### Scenario: Empty field submitted
- GIVEN a SecretRequest requesting fields `[username, password]`
- WHEN the submitter sends values with `password` empty
- THEN the system MUST return a validation error and MUST NOT store any submitted values

### Requirement: Write-Once
Once a SecretRequest is fulfilled, the fill-in link MUST be invalidated and no further submissions accepted. Write-once applies per SecretRequest — a re-request (see below) creates a new SecretRequest with its own lifecycle.

#### Scenario: No second submission after fulfilment
@e2e exclude Server-side write-once contract — the fill-in token is invalidated on fulfilment; covered by PHPUnit, not browser-observable.
- GIVEN a SecretRequest that has already been fulfilled
- WHEN a further submission is attempted on the same fill-in link
- THEN the system MUST reject it and accept no further values for that SecretRequest

### Requirement: Re-request (Update in Place)
The requester MUST be able to create a new SecretRequest against an already-filled Secret (a re-request). This is the mechanism for credential rotation: the external party is asked to submit new values, which overwrite the existing ones in place.

While the re-request is pending, the Secret's existing values remain readable — there is no gap in access. On fulfilment, the new values overwrite the old ones and sync-on-update propagates to all shares automatically.

The same ownership rules apply: a user may re-request for their own secrets or application secrets, but not for another user's secret.

#### Scenario: Re-request for credential rotation
- GIVEN a Secret owned by the requester or an application with a previously fulfilled SecretRequest
- WHEN the requester creates a new SecretRequest against the same Secret
- THEN a new SecretRequest MUST be created with a new token (no new Secret is created)
- AND the existing Secret values MUST remain readable until the new request is fulfilled

#### Scenario: Re-request fulfilled
- GIVEN a re-request is pending and the external party submits new values
- WHEN the submission is accepted
- THEN the new values MUST overwrite the existing Secret fields in place
- AND sync-on-update MUST propagate the new values to all shares
- AND `possibly_compromised_at` MUST be unset if it was set

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
@e2e exclude Terminal-state distinction; driven by SecretRequestServiceTest::testExpireDeletesThePlaceholderAndAttributesTheSystem (status becomes `expired`, not `declined`) and the separate REQUEST_EXPIRED audit event with a system actor.
- GIVEN a request that lapsed because its expiry passed
- WHEN the requester inspects what happened to it
- THEN it MUST be distinguishable from a request they revoked themselves

### Requirement: Session-less Application-Initiated Request Creation

The system MUST provide a session-less way for a registered application to create a `SecretRequest` in its OWN vault, keyed to the application identity and NOT to a Nextcloud user. The operation MUST authenticate the application by a **signed cryptographic proof verified against the application's registered EncryptionSuite certificate** (a JWT assertion or challenge signature, reusing the `JwtAuthService` verification path via `ApplicationSecretRequestService::createForApplicationBySignedProof`) — it MUST NOT accept a `userId`, and MUST NOT accept an `applicationId` alone as sufficient authority. On a valid proof, the operation MUST create the application-owned Secret shell as needed, mint the fill-link `token`, store the `requestedFields`, and return the request together with its token and the derived public fill-link URL. The submitted values MUST later be encrypted under the application's own certificate (write-without-read preserved).

#### Scenario: DI seam creates a request given a valid signed proof
@e2e exclude In-process service seam with no UI surface; covered by ApplicationSecretRequestService application-create unit tests (valid proof → request + token + fill-link).
- **GIVEN** an approved application with an active EncryptionSuite and a valid signed proof over that suite's certificate
- **WHEN** a same-instance caller invokes the DI seam with the proof and a non-empty `requestedFields`
- **THEN** the system MUST create an application-owned Secret shell and a pending `SecretRequest` with a unique token
- **AND** return the request together with its token and the derived fill-link URL

#### Scenario: DI seam rejects appId-only, invalid signature, replayed jti, or wrong certificate
@e2e exclude In-process service seam with no UI surface; covered by ApplicationSecretRequestService negative unit tests (appId-only / bad-signature / replayed-jti / wrong-cert).
- **GIVEN** a caller invokes the DI seam
- **WHEN** the proof is missing (application id supplied alone), has an invalid signature, replays a previously seen `jti` within the assertion lifetime, exceeds the ≤300 s assertion lifetime, or is signed by a key that does not match the application's registered certificate
- **THEN** the operation MUST reject the creation and MUST NOT create any Secret or SecretRequest

#### Scenario: DI seam cannot create in another vault
@e2e exclude In-process service seam with no UI surface; covered by ApplicationSecretRequestService cross-vault unit test.
- **GIVEN** a valid proof for application A
- **WHEN** the caller attempts to direct the creation at application B's or a user's vault
- **THEN** the request MUST be created only in A's vault — never in B's or a user's vault

#### Scenario: Creation refused for a non-approved application or revoked suite
@e2e exclude In-process service seam with no UI surface; covered by ApplicationSecretRequestService guard unit tests (parity with token-issuance guards).
- **GIVEN** an application that is pending/rejected/deleted OR whose EncryptionSuite is revoked/compromised
- **WHEN** a proof is presented to the DI seam
- **THEN** the creation MUST be refused with the same guards enforced at token issuance

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

### Requirement: Fill Link Recovery

The requester MUST be able to recover the fill link of any request whose link is still usable, without re-creating the request.

The link is shown once, when the request is created. If that is the only chance to capture it, closing the dialog strands the request: the token is displayed truncated in the request list precisely so it cannot be read off a screen, so there is no way back to a working URL and the only remedy is to revoke and start again. The machine surface already recognises this need — `Machine Pending-Request Listing` exists "so a fill-link is retrievable after creation" — and a person needs it at least as much as an application does.

Recovery MUST be offered only where the link would actually work. A fulfilled, declined or lapsed request no longer accepts a submission, and handing someone a dead URL is worse than offering nothing. Expiry MUST be evaluated against `expires_at` rather than trusting the stored status, because a lapsed request continues to read as `pending` until a sweeper transitions it.

Recovery MUST NOT widen who can see the token: it is offered to the requester on their own request, and the listing itself still MUST NOT render the token in full.

#### Scenario: Recovering the link after the dialog is closed
@e2e exclude Clipboard interaction in a component; driven by SecretRequestList.spec.js "copies the fill link for a pending request".
- **GIVEN** a pending request whose creation dialog was closed without copying the link
- **WHEN** the requester asks for the link again from the request list
- **THEN** the system MUST provide the same anonymous fill URL the dialog offered

#### Scenario: No recovery offered for a request that cannot be filled
@e2e exclude Row-level affordance; driven by SecretRequestList.spec.js "offers no link for a fulfilled or lapsed request".
- **GIVEN** a request that is fulfilled, declined, or whose `expires_at` has passed
- **WHEN** the requester views it in the list
- **THEN** no fill link MUST be offered for it

#### Scenario: The full token is still never rendered
@e2e exclude Assertion about rendered output; driven by SecretRequestList.spec.js "never renders the full token".
- **WHEN** a request is listed with link recovery available
- **THEN** the row MUST still show the token truncated, and the full token MUST reach the clipboard only on an explicit request

## User Stories

- As a user, I want to request a password from a vendor via a link so that they can submit it securely without me ever seeing it in plain text
- As an administrator, I want to request application secrets from a third party so that I can configure the application without the secrets passing through my hands
- As an external party, I want to submit a secret via a link so that I know it is stored securely immediately
- As a user, I want to revoke a request if I sent the link to the wrong person
- As a user, I want to re-request a secret so that a vendor can submit updated credentials without me ever seeing the new values
- As an administrator, I want to create or re-request secrets for applications so that application credentials can be rotated securely

## Acceptance Criteria

- [ ] A SecretRequest creates an unfilled Secret and a unique fill-in token
- [ ] Any authenticated user can create a SecretRequest for their own secrets or for application secrets
- [ ] Creating a SecretRequest targeting another user's secret is rejected with an authorization error
- [ ] The fill-in link can be used without authentication
- [ ] Submitted values are encrypted with the requester's public certificate before storage
- [ ] After submission, the link is invalidated and the request is marked fulfilled
- [ ] The requester cannot read submitted values without their master password (normal decryption flow)
- [ ] A pending request can be revoked by the requester
- [ ] Revoking a request deletes the unfilled Secret and invalidates the token
- [ ] A fulfilled or revoked token returns an error if accessed again
- [ ] Requester may optionally set an expiry on the request; expired requests reject submissions
- [ ] Requester receives a Nextcloud notification when the request is fulfilled
- [ ] Submissions with any empty requested field are rejected — all fields must be non-empty
- [ ] Standard Nextcloud rate limiting applies to the fill-in endpoint; no additional per-token rate limiting required (token entropy makes guessing infeasible; write-once semantics prevent replay)
- [ ] Each new SecretRequest creates its own unfilled Secret; a re-request targets an existing Secret (no new Secret created)
- [ ] While a re-request is pending, the existing Secret values remain readable
- [ ] On re-request fulfilment, new values overwrite the existing Secret fields in place
- [ ] Sync-on-update propagates re-request fulfilment to all shares; `possibly_compromised_at` is unset if set
- [ ] Revoking a re-request preserves the existing Secret and its current values

## Notes

- The write-without-read property is fundamental here — it is a direct consequence of asymmetric encryption. Any implementation that allows the requester to read submitted values without their master password is a security bug.
- Related ADRs: ADR-003 (encryption architecture — write-without-read property)
