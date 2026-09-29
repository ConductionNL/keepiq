## ADDED Requirements

### Requirement: Hidden custom fields

The system MUST let the owner of a secret give each custom field a kind of `text`, `hidden` or `boolean` in the create and edit dialogs. A `hidden` value MUST be rendered masked in the editor and on the detail sidebar until the user chooses Reveal, and MUST have a Copy action that does not reveal it. The kind MUST be stored inside the same encrypted additional-fields blob as the name and value, and a field without a kind MUST be read as `text`.

#### Scenario: An owner hides a recovery code

- **GIVEN** an owner editing a login at /secrets with an existing text custom field named Recovery code
- **WHEN** the owner sets the field kind to Hidden and saves
- **THEN** the detail sidebar shows the value as dots and a Reveal button, and Copy places the real value on the clipboard

#### Scenario: An old secret still opens

- **GIVEN** a secret saved before this change whose additional fields have no kind
- **WHEN** the owner opens its detail sidebar
- **THEN** every custom field shows as plain text exactly as before

### Requirement: Hidden values in exports

Export and import through CXF MUST carry the field kind: a `hidden` field MUST be exported as a concealed string field and imported back as `hidden`.

#### Scenario: A hidden field survives a CXF round trip

- **GIVEN** a secret with one hidden and one text custom field
- **WHEN** the owner exports it as CXF and imports the file into an empty vault
- **THEN** the imported secret has both fields and the second one is still hidden
