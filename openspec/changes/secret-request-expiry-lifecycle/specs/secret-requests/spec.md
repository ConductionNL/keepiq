## MODIFIED Requirements

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
