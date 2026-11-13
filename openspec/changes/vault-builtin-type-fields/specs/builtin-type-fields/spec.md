## ADDED Requirements

### Requirement: Built-in types carry their own fields

The system MUST give the built-in item types below their own form in the create and edit dialogs, in place of the generic "Secret value" field:

| Type | Value field | Extra fields | Left out |
|---|---|---|---|
| `login` | "Password", masked, required | — | — |
| `certificate` | "Certificate", multi-line, required | Private key (multi-line), Passphrase (masked) | Login |
| `api_key` | "API key", masked, required | API secret (masked) | — |
| `database` | "Password", masked, required | Host, Port, Database | URL; Login is labelled "Username" |
| `ssh_key` | "Private key", multi-line, required | Public key (multi-line), Passphrase (masked) | — |
| `note` | "Note", multi-line, required | — | Login, URL |

The value MUST stay in the secret's `key`, so a certificate stays where `certificate-lifecycle` parses it. A left-out field MUST still be shown in the edit dialog when the secret already holds a value for it, so no stored value becomes unreachable. Extra fields MUST be optional. The other built-in types keep their current forms.

#### Scenario: Creating a certificate

- **GIVEN** a vault user in the create dialog
- **WHEN** they pick the Certificate type
- **THEN** a multi-line "Certificate" field replaces "Secret value", with Private key and Passphrase below it and no Login field
- **AND** Create stays disabled until the Certificate field holds a value
- **AND** the pasted PEM block is saved with its line breaks as the secret's `key`

#### Scenario: Editing a database connection that already has a URL

- **GIVEN** a Database secret whose `url` holds a value
- **WHEN** its owner opens the edit dialog
- **THEN** the URL field is shown with its value next to Host, Port and Database

### Requirement: The create dialog marks the value as required

The create dialog MUST mark the secret's value field as required, with the "(required)" marker the type's own required fields carry and the input's `required` attribute, for every type that shows it, because Create stays disabled without a value.

#### Scenario: Creating a login

- **GIVEN** a vault user in the create dialog
- **WHEN** they pick the Login type
- **THEN** the value field is labelled "Password (required)"
- **AND** Create stays disabled until it holds a value

### Requirement: Built-in field values keep a fixed name

The system MUST store a built-in type's extra field values in the encrypted additional fields under the field's fixed English name ("Private key", "Public key", "Passphrase", "API secret", "Host", "Port", "Database"), whatever the user's language, and MUST show them with a translated label. An empty extra field MUST NOT be stored.

#### Scenario: A Dutch user saves a private key

- **GIVEN** a vault user whose language is Dutch
- **WHEN** they save a certificate with a private key
- **THEN** the additional fields hold it under "Private key"
- **AND** the detail sidebar and the edit dialog show it under the Dutch label

### Requirement: The detail sidebar shows the fields of the type

The detail sidebar MUST show a multi-line value or extra field with its line breaks once revealed, and MUST show a certificate at once, without a show/hide toggle, because it is public by design. It MUST show the type's extra fields in the value's box, below the value and in the type's order, and MUST mask a masked or multi-line one until it is revealed, as it masks the value. Only the additional fields the type does not define MUST be listed under "Additional fields", below the type's fields, each as a label above the value, keeping its line breaks.

#### Scenario: Opening a certificate

- **GIVEN** a Certificate secret with a private key and a passphrase
- **WHEN** its owner opens it in the detail sidebar
- **THEN** the certificate is shown with its line breaks and no show/hide toggle
- **AND** the private key and passphrase are shown below it in the same box, masked until they are revealed, and the private key keeps its line breaks once revealed
- **AND** "Additional fields" lists only the fields the owner added themselves

### Requirement: A request always asks for the required fields of its type

The request dialog MUST list a type's fields with the type's own labels, for a fresh request by the picked type and for any other request by the secret's type. A required value field of a type in the table above MUST be ticked and locked, so the request always asks for it; a field the secret already holds stays unlocked on a plain request, so the dialog never compels an overwrite. Left-out fields MUST NOT be offered.

#### Scenario: Requesting a new certificate

- **GIVEN** a vault user creating a fresh request
- **WHEN** they pick the Certificate type
- **THEN** "Certificate" is listed ticked and cannot be unticked
- **AND** Private key and Passphrase are listed unticked, and Login is not listed

### Requirement: The fill page shows the fields of the requested type

The public request answer MUST name the type of the secret the request fills, and nothing else about it. The fill page MUST label each requested field as the request dialog did, render a multi-line field as a text area that keeps line breaks, and mask a masked field, in the inputs and in the summary after submitting.

#### Scenario: Filling a certificate request

- **GIVEN** a request for a certificate's Certificate and Private key fields
- **WHEN** the recipient opens the fill link
- **THEN** both fields are text areas labelled "Certificate" and "Private key"
- **AND** the PEM blocks they paste are delivered with their line breaks
