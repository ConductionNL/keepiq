## ADDED Requirements

### Requirement: Adjustable CSV mapping

The import wizard MUST show, for a generic CSV file, the detected mapping from each target field to a source column and MUST let the user change any of them before the import starts. The preview MUST update to the changed mapping, and the import MUST use exactly the mapping shown.

#### Scenario: A user fixes a wrong guess

- **GIVEN** a user importing a CSV whose password column is named Secret Key
- **WHEN** the wizard guessed Notes for it and the user picks Password for that column
- **THEN** the preview shows the values under Password and the import stores them as passwords

#### Scenario: A required field is unmapped

- **GIVEN** a user in the mapping step
- **WHEN** the user sets Name to no column
- **THEN** the Import button is disabled and the step says a name column is required

### Requirement: Revealing sensitive preview cells

The preview MUST mask login and password cells and MUST let the user reveal one cell at a time.

#### Scenario: A user checks a password cell

- **GIVEN** the preview of a CSV import
- **WHEN** the user chooses Reveal on one password cell
- **THEN** that cell shows its value and the others stay masked
