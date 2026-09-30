## ADDED Requirements

### Requirement: Recently used on the dashboard

The dashboard MUST show a Recently used widget that lists the signed-in user's most recently read secrets, newest first, at most five, one row per secret with its name and a relative time. A row MUST open that secret. The widget MUST list only secrets the user still holds and MUST show an empty state when there are none.

#### Scenario: A user sees what they opened last

- **GIVEN** a user who opened three different secrets and one of them twice
- **WHEN** the user opens the dashboard
- **THEN** the widget lists three rows, the twice-opened secret once, newest first

#### Scenario: A row opens the secret

- **GIVEN** the Recently used widget with rows
- **WHEN** the user clicks a row
- **THEN** the secret list opens with that secret selected in the sidebar

#### Scenario: A deleted secret is not listed

- **GIVEN** a user who read a secret that has since been deleted
- **WHEN** the user opens the dashboard
- **THEN** the widget does not list it
