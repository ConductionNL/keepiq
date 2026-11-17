## ADDED Requirements

### Requirement: A secure note holds text without a login

A vault user MUST be able to store a secure note: a secret of the system type `note` whose value is free text. When the user picks Secure note in the new secret dialog, the dialog MUST label the value field Note. Username and website address MUST stay optional. The note text MUST be encrypted in the browser like any other secret value. The detail sidebar MUST show the value under the label Note.

#### Scenario: A vault user keeps the alarm codes as a secure note

- **GIVEN** a vault user in the new secret dialog on /secrets
- **WHEN** the user picks the type Secure note, enters a name and the codes as text, and saves without a username or address
- **THEN** the secret is stored with type `note` and its text encrypted
- **AND** the detail sidebar shows the text under Note
