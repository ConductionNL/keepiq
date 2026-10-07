## ADDED Requirements

### Requirement: Default item type and view

The system MUST let a user choose a default item type and a default list view in personal settings and MUST persist both through the existing preferences endpoint. The create dialog MUST preselect the saved type, and the secret list MUST open in the saved view. A saved type that no longer exists MUST fall back to `login`.

#### Scenario: A user sets SSH Key as the default type

- **GIVEN** a signed-in user on personal settings
- **WHEN** the user picks SSH Key as the default item type and saves, then clicks New secret
- **THEN** the create dialog opens with SSH Key selected

#### Scenario: The list opens in the saved view

- **GIVEN** a user who saved the cards view
- **WHEN** the user opens /secrets in a new session
- **THEN** the list renders in the cards view

#### Scenario: A deleted type falls back

- **GIVEN** a user whose saved default type was deleted by an administrator
- **WHEN** the user clicks New secret
- **THEN** the dialog preselects Login and shows no error
