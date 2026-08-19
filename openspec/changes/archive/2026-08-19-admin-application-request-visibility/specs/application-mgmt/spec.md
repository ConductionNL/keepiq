## ADDED Requirements

### Requirement: Outstanding Application Requests Visible to Administrators

An administrator MUST be able to see the secret requests an application has created, and MUST be able to revoke them.

Today they cannot. `created_by` for an application request is `application:<id>`, and the user-facing listing matches `created_by` against the acting user's id, so no person can enumerate them; the target Secrets are application-owned and appear in no user's vault; and the only lister is the application's own Bearer-authenticated endpoint. Creation is recorded in the audit trail, so the events exist with nowhere to read them as state.

This matters because a pending fill link is a bearer credential in a URL. An administrator accountable for an approved application MUST be able to answer what credentials it is currently asking humans for, and MUST be able to end a circulating link without the application's cooperation.

Visibility MUST be scoped to administrators, not to whoever registered the application: an application's vault belongs to no single user, and registration is a historical act rather than continuing responsibility.

The listing MUST NOT render a request's full token, and MUST NOT expose any submitted value — write-without-read is unaffected by who is looking.

#### Scenario: An administrator sees what an application is asking for
@e2e exclude No Playwright coverage of the admin application page yet; driven by ApplicationRequestAdminControllerTest::testAnAdministratorSeesTheApplicationsRequests, SecretRequestServiceTest::testListForApplicationReturnsThatApplicationsRequestsNewestFirst and the SecretRequestList vitest "renders the application rows". Verified live on 2026-08-19: the endpoint returned each application's own two requests with their status and requested field names.
- **GIVEN** an application has created pending secret requests
- **WHEN** an administrator opens that application
- **THEN** those requests MUST be listed with their status, requested field names and expiry

#### Scenario: A non-administrator cannot list them
@e2e exclude Authorization, asserted at both layers rather than through a browser: ApplicationRequestAdminControllerTest::testANonAdministratorIsRefused (and ::testAnAnonymousCallerIsRefused) plus SecretRequestServiceTest::testListForApplicationRefusesANonAdmin, which fails when the service guard is removed. Registrar identity is not an input to either check. Verified live: alice received 403.
- **WHEN** a user who is not an administrator requests an application's secret requests
- **THEN** the system MUST refuse, regardless of whether that user registered the application

#### Scenario: The user's own listing is unchanged
@e2e exclude Regression guard on a query, not a UI flow; driven by SecretRequestServiceTest::testListByUserStillQueriesTheRawUid, which pins that the uid is passed with no application prefix — the reason a user listing can never match an application's rows.
- **GIVEN** an instance with both user-created and application-created requests
- **WHEN** a user lists their own requests
- **THEN** only requests they created MUST be returned, exactly as before

#### Scenario: An administrator revokes a circulating fill link
@e2e exclude Driven by SecretRequestServiceTest::testAdminRevokeDeletesTheUnfilledApplicationPlaceholder, ::testAdminRevokeNeverDeletesAFilledApplicationSecret, ::testAdminRevokeWillNotDeleteAnotherApplicationsSecret and ::testRevokeForApplicationRefusesARequestOfAnotherActor (which fails when the created_by check is removed), plus the vitest "asks before revoking, and revokes through the application endpoint". NOT verified live: doing so would hard-delete a seeded placeholder Secret on the development instance, and the request row cannot be restored.
- **GIVEN** an application has a pending request whose link is in circulation
- **WHEN** an administrator revokes it
- **THEN** the token MUST stop being fillable
- **AND** the unfilled placeholder Secret MUST be deleted, per the Revoke Request requirement

#### Scenario: The listing does not leak the token or the values
@e2e exclude Driven by the vitest "never renders a full token", which asserts the truncated form is shown and neither full token appears anywhere in the rendered output. The API deliberately still returns the token — the copy-link action needs it — so truncation is the view's job, exactly as on the user side. No submitted value is readable from a listing because the response carries only metadata (write-without-read, ADR-003).
- **WHEN** an application's requests are listed for an administrator
- **THEN** each row MUST show the token truncated
- **AND** no submitted value MUST be readable from the listing
