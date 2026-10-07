## ADDED Requirements

### Requirement: Save or update prompt

After a login form is submitted, the extension MUST show a prompt on the page offering to save the login. When a saved login exists for the same origin and username with a different password, the prompt MUST offer Update and MUST update that secret instead of creating a new one. The prompt MUST NOT appear for a submit that matches an existing saved password, and MUST NOT be readable by page scripts.

#### Scenario: A new login is offered

- **GIVEN** a user logged in to the extension who submits a login form on a site with no saved login
- **WHEN** the page reloads after the submit
- **THEN** a prompt offers Save, and Save creates one secret

#### Scenario: A changed password is offered as an update

- **GIVEN** a saved login for the same site and username
- **WHEN** the user submits a different password
- **THEN** the prompt offers Update and choosing it changes that secret without creating a duplicate

#### Scenario: An unchanged login is not offered

- **GIVEN** a saved login
- **WHEN** the user submits the same password
- **THEN** no prompt appears
