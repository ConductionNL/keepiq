# Import column mapping Specification

**Status**: done

**OpenSpec changes:**
- [portability-import-field-mapping](../../changes/archive/2026-09-29-portability-import-field-mapping/) _(archived 2026-09-29)_

## Purpose
A user importing a generic CSV sees which column goes to which field, can change it before the import, and can reveal one masked preview cell at a time. Parity row portability-03.

## Requirements

### Requirement: Adjustable CSV mapping

The import wizard MUST show, for a generic CSV file, the detected mapping from each target field to a source column and MUST let the user change any of them before the import starts. The preview MUST update to the changed mapping, and the import MUST use exactly the mapping shown.

#### Scenario: A user fixes a wrong guess

@e2e exclude Needs an unlocked vault with a master password on the test instance; covered by vitest tests/store/importMapping.spec.js 're-parses the rows when a column is mapped to another field'.

- **GIVEN** a user importing a CSV whose password column is named Secret Key
- **WHEN** the wizard guessed Notes for it and the user picks Password for that column
- **THEN** the preview shows the values under Password and the import stores them as passwords

#### Scenario: A required field is unmapped

@e2e exclude Needs an unlocked vault on the test instance; covered by vitest tests/dialogs/ImportWizardDialog.mapping.spec.js 'blocks Import until a column is mapped to the name'.

- **GIVEN** a user in the mapping step
- **WHEN** the user sets Name to no column
- **THEN** the Import button is disabled and the step says a name column is required

### Requirement: Revealing sensitive preview cells

The preview MUST mask login and password cells and MUST let the user reveal one cell at a time.

#### Scenario: A user checks a password cell

@e2e exclude Needs an unlocked vault on the test instance; covered by vitest tests/dialogs/ImportWizardDialog.mapping.spec.js 'reveals only the cell that was asked for'.

- **GIVEN** the preview of a CSV import
- **WHEN** the user chooses Reveal on one password cell
- **THEN** that cell shows its value and the others stay masked
