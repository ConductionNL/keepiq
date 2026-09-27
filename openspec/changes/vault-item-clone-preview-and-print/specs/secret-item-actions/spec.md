## ADDED Requirements

### Requirement: Clone a secret

The system MUST offer a Clone action on a secret the user holds, except passkey items, that opens the create dialog prefilled with the source's type, name followed by " (copy)", address, username, value and additional fields, in the same folder. Saving MUST create a new secret through `POST /api/v1/secrets` with ciphertext encrypted in the browser for the user's own active suite. Attachments, shares, version history and passkey material MUST NOT be copied, and the dialog MUST say so.

#### Scenario: A vault user clones a login

- **GIVEN** a vault user viewing their login "Grafana staging" in the secret detail sidebar on /secrets
- **WHEN** the user chooses Clone, changes the address and saves
- **THEN** a new secret "Grafana staging (copy)" exists with its own id and the changed address
- **AND** it has no shares and no attachments

### Requirement: Preview an attachment in the browser

The system MUST offer a Preview action for attachments whose decrypted content type is `image/png`, `image/jpeg`, `image/gif`, `image/webp`, `application/pdf` or `text/plain`. The preview MUST decrypt the file in the browser and render it from an in-memory object URL in a modal, with PDFs in a sandboxed frame, and MUST revoke the URL when the modal closes. Other types, including SVG, MUST offer Download only. No preview MUST be produced on the server.

#### Scenario: A vault user previews a scanned recovery sheet

- **GIVEN** a vault user whose login has a PNG attachment
- **WHEN** the user chooses Preview in the attachments box of the secret detail sidebar
- **THEN** the image is shown in a modal
- **AND** no file is saved to the user's device

#### Scenario: An SVG attachment is not previewed

- **GIVEN** an attachment whose content type is `image/svg+xml`
- **WHEN** the attachments box renders
- **THEN** the attachment offers Download and no Preview

### Requirement: Print a login or show its password as a QR code

The system MUST offer Print and Show as QR code actions on a login the user may reveal. Both MUST first verify the master password in the browser with the same proof of knowledge the plaintext export uses. Print MUST render a sheet with the name, address, username and password and open the browser's print dialog. Show as QR code MUST render the password as a QR code in a modal, generated in the browser. Neither MUST include a one-time code seed, and neither MUST send plaintext to the server.

#### Scenario: A vault user shows a Wi-Fi password as a QR code

- **GIVEN** a vault user viewing the login "Guest Wi-Fi"
- **WHEN** the user chooses Show as QR code and enters the master password
- **THEN** a QR code of the password is shown in a modal

#### Scenario: A wrong master password blocks printing

- **GIVEN** a vault user who chooses Print on a login
- **WHEN** the user enters a wrong master password
- **THEN** no print sheet is rendered and the dialog says the password is wrong
