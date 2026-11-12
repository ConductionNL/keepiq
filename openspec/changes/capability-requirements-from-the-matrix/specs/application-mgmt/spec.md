## ADDED Requirements

### Requirement: The application page lists the secrets in its vault

The application detail page MUST list the secrets in the application's vault with their name, type and creation date, for the administrators and the registrant who may manage the application. The list MUST be served from `GET /api/v1/secrets` filtered on the application as owner, and MUST return metadata only: no `key`, `login`, `additional_fields`, `totp` or `passkeys`, encrypted or not. A user who may not manage the application MUST get the same empty answer as for an application without secrets, so the list reveals nothing about another application's vault.

#### Scenario: An administrator sees what an application holds

- **GIVEN** an active application `zgw-connector` with the secrets `zgw-api-token` and `db-password` in its vault
- **WHEN** an administrator opens the application detail page at /applications/{id}
- **THEN** the Secrets panel lists both secrets with their type and creation date
- **AND** no value of either secret is shown or sent to the browser

#### Scenario: A user who may not manage the application sees nothing

- **GIVEN** a regular user who is neither an administrator nor the registrant of `zgw-connector`
- **WHEN** the user requests the secrets of that application
- **THEN** the answer is an empty list
