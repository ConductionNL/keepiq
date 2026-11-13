## ADDED Requirements

### Requirement: Custom fields carry a schema

The system MUST let the owner of a secret give each custom field a schema in the create and edit dialogs, using the JSON Schema vocabulary of OpenRegister's property editor. A schema MUST have a `type` of `string`, `integer`, `number`, `boolean` or `object`. A `string` MAY have a `format` of `text`, `password`, `uri`, `email`, `hostname`, `ipv4`, `ipv6`, `date`, `date-time`, `time`, `uuid` or `color-hex`, and MAY have `minLength`, `maxLength` and `pattern`. An `integer` MAY have `minimum` and `maximum`; a `number` MAY also have `multipleOf`. A `boolean` and an `object` have no limits.

The schemas MUST be stored inside the same encrypted additional-fields blob as the values, under the reserved member `@schema`, as a map from field name to schema. A field without an entry in `@schema`, and every field of a blob saved before this change, MUST be read as `{"type": "string"}`. Every value MUST stay a string in the blob: an integer or number as its decimal text, a boolean as `"true"` or `"false"`, an object as JSON text. The name `@schema` MUST be refused as a field name, and every reader that lists fields MUST leave it out.

#### Scenario: An owner limits a port number

- **GIVEN** an owner editing a Database secret with a custom field named Port
- **WHEN** the owner sets its type to integer with a minimum of 1 and a maximum of 65535, and saves
- **THEN** the blob holds `"Port": "5432"` and `"@schema": {"Port": {"type": "integer", "minimum": 1, "maximum": 65535}}`
- **AND** the detail sidebar lists Port but no field named `@schema`

#### Scenario: An old secret still opens

- **GIVEN** a secret saved before this change whose additional fields have no `@schema` member
- **WHEN** the owner opens its detail sidebar
- **THEN** every custom field shows as plain text exactly as before

### Requirement: Presets pick a schema, Advanced shows it

The dialogs MUST offer a preset per custom field: Text (`string`), Multi-line text (`string`, format `text`), Password (`string`, Hidden on), URL (`string`, format `uri`), Email (`string`, format `email`), Number (`integer`), Yes/no (`boolean`), Date (`string`, format `date`) and JSON object (`object`). Picking a preset MUST fill in the schema. An Advanced part, closed by default, MUST show the type, format, limits and the Hidden switch of the field, and changing anything there that no preset matches MUST show the preset as Custom.

#### Scenario: Picking the URL preset

- **GIVEN** an owner adding a custom field named Webhook in the create dialog
- **WHEN** the owner picks the URL preset and opens Advanced
- **THEN** Advanced shows type `string` and format `uri`
- **AND** setting a maximum length there changes the preset to Custom

### Requirement: Any custom field can be hidden

A schema MUST carry an optional `hidden` flag, on every type. A hidden value MUST be rendered masked in the editor and on the detail sidebar until the user chooses Reveal, and MUST have a Copy action that does not reveal it. Once revealed, the value MUST be shown as its type and format prescribe.

#### Scenario: A hidden service account

- **GIVEN** a custom field of type `object` with `hidden` set, holding a service-account JSON
- **WHEN** the owner opens the secret in the detail sidebar
- **THEN** the value shows as dots with Reveal and Copy
- **AND** after Reveal it shows in the read-only JSON viewer

### Requirement: Each schema has its own input and display

The dialogs and the detail sidebar MUST render a custom field by its schema: a text area for format `text`, a URL field and a link that opens in a new tab for `uri`, an email field for `email`, a whole-number field for `integer`, a number field for `number`, a switch and Yes or No for `boolean`, a date picker and the date in the user's locale for `date`, and nextcloud-vue's `CnJsonViewer` (editable in the dialogs, read-only on the sidebar) for `object`. Every other schema MUST use a plain text field.

#### Scenario: A JSON object on the sidebar

- **GIVEN** a custom field of type `object` without `hidden`
- **WHEN** the owner opens the secret in the detail sidebar
- **THEN** the value shows in the read-only JSON viewer with highlighting and a Copy action

### Requirement: A value must fit its schema

The system MUST NOT store a value that does not fit its field's schema from any Keepiq screen. The create and edit dialogs MUST block saving and name the field and the rule it breaks. A schema change MUST NOT be saved while the field's current value does not fit the new schema. An import MUST keep a value that does not fit, but MUST drop that field's schema so it reads as text. Because the server never sees the values, a value written through the API that does not fit MUST still be shown, as plain text with a warning.

#### Scenario: A port out of range

- **GIVEN** an owner editing a Port field whose schema has a maximum of 65535
- **WHEN** the owner enters 70000 and saves
- **THEN** saving is blocked with a message that Port must be at most 65535

#### Scenario: Tightening a schema

- **GIVEN** a text field holding a 40-character value
- **WHEN** the owner sets its maximum length to 32 and saves without changing the value
- **THEN** saving is blocked until the value is 32 characters or shorter, or the limit is raised

#### Scenario: An import with a value that does not fit

- **GIVEN** an import file with a field named Port, typed as a number, that holds "n/a"
- **WHEN** the owner imports the file
- **THEN** the secret keeps Port with the value "n/a" and no schema, so it shows as text

### Requirement: A request carries the schemas of its fields

A secret request MUST store each requested custom field's schema with the request, keyed by the field's name, and the public request answer MUST return it to the fill page. The fill page MUST render and check each field by its schema, as the dialogs do, and MUST NOT submit a value that does not fit. A submitted field MUST keep its schema when it is merged into the secret.

#### Scenario: Filling a requested PIN

- **GIVEN** a request for a field named PIN with schema `{"type": "string", "pattern": "^[0-9]{4,6}$", "hidden": true}`
- **WHEN** the recipient opens the fill link and types "12a4"
- **THEN** the field is masked and submitting is blocked until the PIN is 4 to 6 digits
- **AND** after a valid submission the owner's secret holds PIN with the same schema

### Requirement: Schemas survive export and import

Export and import through CXF MUST carry each field's schema: a hidden field MUST be exported as a concealed field and imported back with `hidden` set, and the type, format and limits MUST round-trip. Formats without a CXF counterpart MUST be carried in the field's extension data.

#### Scenario: A schema survives a CXF round trip

- **GIVEN** a secret with one hidden text field and one integer field with a minimum and maximum
- **WHEN** the owner exports it as CXF and imports the file into an empty vault
- **THEN** the imported secret has both fields with the same schemas

### Requirement: Applications are told about the schema member

The application API documentation MUST state that the decrypted additional-fields blob delivered to an application can contain the reserved member `@schema`, give its shape, and tell applications to skip it when they list fields.

#### Scenario: An application reads a typed secret

- **GIVEN** an application with access to a secret whose fields carry schemas
- **WHEN** it decrypts the additional fields
- **THEN** it finds the fields and an `@schema` member shaped as the documentation describes
