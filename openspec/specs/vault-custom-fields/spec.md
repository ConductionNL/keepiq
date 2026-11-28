# vault-custom-fields Specification

## Purpose
Give each custom field a schema, so the dialogs, the sidebar and the fill page enter, check and show it by its kind, a hidden field stays masked until the owner reveals it, and the schema survives requests, export and import.

## Requirements

### Requirement: Custom fields carry a schema

The system MUST let the owner of a secret give each custom field a schema in the create and edit dialogs, using the JSON Schema vocabulary of OpenRegister's property editor. A schema MUST have a `type` of `string`, `integer`, `number`, `boolean` or `object`. A `string` MAY have a `format` of `text`, `password`, `uri`, `email`, `hostname`, `ipv4`, `ipv6`, `date`, `date-time`, `time`, `uuid` or `color-hex`, and MAY have `minLength`, `maxLength` and `pattern`. An `integer` MAY have `minimum` and `maximum`; a `number` MAY also have `multipleOf`. A `boolean` and an `object` have no limits.

The schemas MUST be stored inside the same encrypted additional-fields blob as the values, under the reserved member `@schema`, as a map from field name to schema. A field without an entry in `@schema`, and every field of a blob saved before this change, MUST be read as `{"type": "string"}`. The web app MUST write every value as a string: an integer or number as its decimal text, a boolean as `"true"` or `"false"`, an object as JSON text; a reader MUST take a value stored as a JSON number by an older client as its text. A field that an unstable 0.3.14 build stored as `{"value": …, "kind": "hidden"}` or `"kind": "boolean"` MUST be read as its value with the schema that kind implies, hidden text or yes/no, unless `@schema` gives it one. The name `@schema` MUST be refused as a field name, and every reader that lists fields MUST leave it out; a field already called `@schema` whose value is not a map of schemas, from before the name was reserved or from an import, MUST be kept under the name "@schema (imported)". A custom field MUST NOT take the name of one of the type's own fields, which are stored under the same names.

#### Scenario: An owner limits a port number

- **GIVEN** an owner editing a Login secret with a custom field named Port
- **WHEN** the owner sets its type to integer with a minimum of 1 and a maximum of 65535, and saves
- **THEN** the blob holds `"Port": "5432"` and `"@schema": {"Port": {"type": "integer", "minimum": 1, "maximum": 65535}}`
- **AND** the detail sidebar lists Port but no field named `@schema`

#### Scenario: An old secret still opens

- **GIVEN** a secret saved before this change whose additional fields have no `@schema` member
- **WHEN** the owner opens its detail sidebar
- **THEN** every custom field shows as plain text exactly as before

#### Scenario: A hidden field from an earlier build

- **GIVEN** a secret whose additional fields hold `"Recovery code": {"value": "RC-4711", "kind": "hidden"}`, as an unstable 0.3.14 build saved it
- **WHEN** the owner opens its detail sidebar
- **THEN** Recovery code shows masked until they choose Show, and Copy copies RC-4711

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

The dialogs and the detail sidebar MUST render a custom field by its schema: a text area for format `text`, a URL field and a link that opens in a new tab for `uri` (only for an `http`, `https` or `mailto` address: any other scheme, such as `javascript:`, MUST show as text), an email field for `email`, a whole-number field for `integer`, a number field for `number`, a switch and Yes or No for `boolean`, a date picker and the date in the user's locale for `date`, and nextcloud-vue's `CnJsonViewer` (editable in the dialogs, read-only on the sidebar) for `object`. Every other schema MUST use a plain text field.

#### Scenario: A JSON object on the sidebar

- **GIVEN** a custom field of type `object` without `hidden`
- **WHEN** the owner opens the secret in the detail sidebar
- **THEN** the value shows in the read-only JSON viewer with highlighting and a Copy action

### Requirement: A value must fit its schema

The web app's create and edit dialogs MUST NOT store a value that does not fit its field's schema: they MUST block saving and name the field and the rule it breaks. The edit dialog checks only the fields changed in it, so a stored value that does not fit, written elsewhere, shows its warning without blocking an unrelated change. The dialogs MUST also block saving a field named like one of the type's own fields and a schema the server would refuse. The browser extension and the mobile app keep each field's schema through an edit but do not check values against it. A schema change MUST NOT be saved while the field's current value does not fit the new schema. An import MUST keep a value that does not fit, but MUST drop that field's schema so it reads as text. Because the server never sees the values, a value written through the API that does not fit MUST still be shown, as plain text with a warning.

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

A secret request MUST store each requested custom field's schema with the request, keyed by the field's name, and the public request answer MUST return it to the fill page. The server MUST refuse a schema whose `pattern` does not compile, repeats a group that holds a quantifier or an alternation at any depth, such as `(a+)+`, `(a|aa)+` or `(a+){10}`, or uses a construct that PCRE and JavaScript read differently, such as inline flags, possessive quantifiers, atomic groups, a lookbehind, `\A`, `\z`, `\Z`, comments, back-references, a POSIX class, or a group name used twice or longer than 32 characters, because the fill page runs it on every keystroke. It MUST also refuse a pattern with more than three choice points, counting each variable quantifier (`?`, `*`, `+`, `{m,}`, or `{m,n}` with m below n, but not a fixed `{n}`) and each group or top-level pattern that holds an alternation, plus one more when the pattern is not anchored with `^` or has a top-level alternation, since a failed match tries every combination of them, as in `^\d*\d*\d*\d*x$` or `^(a|a)(a|a)(a|a)(a|a)$`. It MUST also refuse a pattern longer than 500 characters, or longer than 4096 with each counted group written out as often as it repeats. The browser MUST apply the same rule: Advanced MUST flag such a pattern, no value MUST fit it, an import MUST drop it also from an empty field, and a pattern MUST NOT be tested against a value longer than 256 characters. The fill page MUST render and check each field by its schema, as the dialogs do, and MUST NOT submit a value that does not fit; a schema that only gives a string limits leaves a field whose name looks secret masked, as it is without one.

The schemas on the secret MUST NOT come from a fill, because whoever holds a fill link seals the filled blob and can put anything in it. Making a request MUST NOT write into the secret, so a fresh request's placeholder stays empty until it is filled. The fill page MUST send only the values, the server MUST keep every filled additional-fields blob of a user-owned secret apart as pending, also when the secret has no additional fields yet, and the owner's browser MUST take only the values from it when merging: a `@schema` member in a filled blob MUST be ignored. The server MUST store, with each pending blob, the checked schemas of the request that filled it, and MUST return them in `pendingFieldSchemas`, in the same order as `pendingAdditionalFields`, so they survive the request being deleted, as trashing the secret does. A filled field MUST keep the schema stored on the secret; a filled field without one MUST get the schema stored with its blob. A fill can still set the value of any field, as before. A request for an application's secret MUST NOT carry field schemas, and the server MUST refuse one that does with a 400: a fill writes an application's additional-fields blob as the fill page sealed it to the application's key, so the server cannot add the schemas to it and no owner's browser merges it.

#### Scenario: Filling a requested PIN

- **GIVEN** a request for a field named PIN with schema `{"type": "string", "pattern": "^[0-9]{4,6}$", "hidden": true}`
- **WHEN** the recipient opens the fill link and types "12a4"
- **THEN** the field is masked and submitting is blocked until the PIN is 4 to 6 digits
- **AND** after a valid submission the owner's secret holds PIN with the same schema

#### Scenario: A fill tries to change a schema

- **GIVEN** a secret whose Host field is hidden, and a request for its PIN
- **WHEN** a fill seals a blob whose `@schema` makes Host plain text and PIN a boolean
- **THEN** the owner's secret keeps Host hidden and PIN with the schema the request gave it
- **AND** only the PIN value from the fill is merged

#### Scenario: A request for an application's secret names a schema

- **GIVEN** a secret owned by an application
- **WHEN** a user asks for its PIN with schema `{"type": "string", "hidden": true}`
- **THEN** the request is refused with a 400 and no request is stored

### Requirement: Schemas survive export and import

Export and import through CXF MUST carry each field's schema: a hidden field MUST be exported as a concealed field and imported back with `hidden` set, also a built-in type's own masked field without a stored schema, and the type, format and limits MUST round-trip. Formats without a CXF counterpart MUST be carried in the field's extension data. An import that drops a schema a value does not fit MUST keep a hidden field hidden. An imported field whose label is a reserved name (`key`, `login`, `url` or `@schema`, in any case) MUST be stored under that label with " (imported)" added, so its value is kept. An SSH key's public key MUST be imported into, and exported from, the `publicKey` member that the SSH Key form reads (`vault-ssh-key-item`).

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
