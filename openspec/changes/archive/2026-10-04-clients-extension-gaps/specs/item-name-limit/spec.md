## ADDED Requirements

### Requirement: A name has at most 255 characters
An item name MUST have at most 255 characters, the width of the server's `name` column. The import MUST refuse a longer name per item, so the rest of the batch still lands. The extension's item form MUST refuse it before saving. Addresses and values keep the import limit of 4096 characters.

#### Scenario: Import a name that is too long
@e2e exclude Server-side import validation, no browser surface. Covered by tests/Unit/Service/ImportServiceTest.php (testNameLongerThanTheColumnIsRefused).
- **GIVEN** an import chunk with a 255-character name and a 256-character name
- **WHEN** it is committed
- **THEN** the first item is created and the second fails with a message naming the limit

#### Scenario: Type a name that is too long in the extension
@e2e exclude Browser-extension popup form. Covered by tests/extension/itemForm.spec.js ("allows a name of 255 characters and refuses a longer one, the width of the server column").
- **GIVEN** the extension's item form
- **WHEN** the name has 256 characters
- **THEN** the form says "At most 255 characters" and does not save
