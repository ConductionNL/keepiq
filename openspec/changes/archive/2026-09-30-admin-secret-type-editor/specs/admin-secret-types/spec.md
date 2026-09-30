## ADDED Requirements

### Requirement: Item type definitions

The system MUST let an administrator create a global item type with a name, a label and an ordered list of fields, each with a label, a kind and a required flag, and MUST let the administrator edit and delete it. A type definition MUST be visible to every user as a choice in the create dialog. Deleting a type MUST leave its secrets readable and move them to the login type as the type service already does.

#### Scenario: An administrator creates a type

- **GIVEN** an administrator on the Item types admin page
- **WHEN** the administrator creates Server access with fields Host (url, required), Port (text) and Root password (hidden) and saves
- **THEN** the type appears in every user's create dialog

#### Scenario: A user fills a typed item

- **GIVEN** a user choosing Server access in the create dialog
- **WHEN** the user leaves Host empty and saves
- **THEN** the dialog blocks the save and marks Host as required

#### Scenario: A regular user cannot define a global type

- **GIVEN** a user without the admin role
- **WHEN** the user posts a global type to the secret types route
- **THEN** the server answers 403

### Requirement: Typed fields storage

Values entered for the fields of a type MUST be stored inside the encrypted additional fields blob, and the server MUST NOT receive them in plain text.

#### Scenario: Values stay encrypted

- **GIVEN** a user saving a Server access item
- **WHEN** the request is sent to the server
- **THEN** the request body holds only ciphertext for the field values
