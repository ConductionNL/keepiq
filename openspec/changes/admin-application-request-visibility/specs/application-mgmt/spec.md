## ADDED Requirements

### Requirement: Outstanding Application Requests Visible to Administrators

An administrator MUST be able to see the secret requests an application has created, and MUST be able to revoke them.

Today they cannot. `created_by` for an application request is `application:<id>`, and the user-facing listing matches `created_by` against the acting user's id, so no person can enumerate them; the target Secrets are application-owned and appear in no user's vault; and the only lister is the application's own Bearer-authenticated endpoint. Creation is recorded in the audit trail, so the events exist with nowhere to read them as state.

This matters because a pending fill link is a bearer credential in a URL. An administrator accountable for an approved application MUST be able to answer what credentials it is currently asking humans for, and MUST be able to end a circulating link without the application's cooperation.

Visibility MUST be scoped to administrators, not to whoever registered the application: an application's vault belongs to no single user, and registration is a historical act rather than continuing responsibility.

The listing MUST NOT render a request's full token, and MUST NOT expose any submitted value — write-without-read is unaffected by who is looking.

#### Scenario: An administrator sees what an application is asking for
- **GIVEN** an application has created pending secret requests
- **WHEN** an administrator opens that application
- **THEN** those requests MUST be listed with their status, requested field names and expiry

#### Scenario: A non-administrator cannot list them
- **WHEN** a user who is not an administrator requests an application's secret requests
- **THEN** the system MUST refuse, regardless of whether that user registered the application

#### Scenario: The user's own listing is unchanged
- **GIVEN** an instance with both user-created and application-created requests
- **WHEN** a user lists their own requests
- **THEN** only requests they created MUST be returned, exactly as before

#### Scenario: An administrator revokes a circulating fill link
- **GIVEN** an application has a pending request whose link is in circulation
- **WHEN** an administrator revokes it
- **THEN** the token MUST stop being fillable
- **AND** the unfilled placeholder Secret MUST be deleted, per the Revoke Request requirement

#### Scenario: The listing does not leak the token or the values
- **WHEN** an application's requests are listed for an administrator
- **THEN** each row MUST show the token truncated
- **AND** no submitted value MUST be readable from the listing
