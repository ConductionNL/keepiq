## ADDED Requirements

### Requirement: Add attachments from the create and edit secret dialogs

The create and edit secret dialogs MUST offer a drop zone with an Add file button at the bottom of the form, where the user can drop or pick one or more files. The files MUST wait in the dialog and MUST NOT be uploaded before Create or Save succeeds. After the secret is saved, each file MUST be encrypted in the browser and uploaded exactly as an upload from the secret detail sidebar, under the same per-attachment size limit and per-user quota. When an upload is refused, the secret MUST stay saved, the dialog MUST stay open and show the refused file with the server's reason and Retry and Remove actions, and retrying MUST NOT create the secret again. Files MUST only be added where the sidebar allows uploads: the user owns the secret and the vault is not offline read-only. Files already listed when that stops being true MUST stay listed with the reason, and the save MUST wait until they are removed. The edit dialog MUST report the secret saved to its caller once, when it closes.

#### Scenario: A vault user creates a login with a recovery-codes file

- **GIVEN** a vault user with an unlocked vault in the New secret dialog on /secrets
- **WHEN** the user fills in a login, drops "recovery-codes.png" on the drop zone and chooses Create secret
- **THEN** the login is created and "recovery-codes.png" is uploaded as its attachment, encrypted in the browser
- **AND** the dialog closes

#### Scenario: Cancel uploads nothing

- **GIVEN** a vault user in the Edit secret dialog with a file waiting in the drop zone
- **WHEN** the user chooses Cancel
- **THEN** no attachment is uploaded

#### Scenario: A file over the size limit is refused after the save

- **GIVEN** a vault user in the New secret dialog with a file larger than the per-attachment limit waiting
- **WHEN** the user chooses Create secret
- **THEN** the secret is created
- **AND** the dialog stays open and shows the file with the server's reason, Retry and Remove
- **AND** removing the file closes the dialog without creating a second secret

#### Scenario: Files stay listed when the destination changes

- **GIVEN** a vault user in the New secret dialog with "codes.png" waiting in the drop zone
- **WHEN** the user picks a team folder they can write to but do not own
- **THEN** "codes.png" stays listed with the reason it cannot be added
- **AND** Create secret is unavailable until the file is removed

#### Scenario: No drop zone on a shared copy

- **GIVEN** a vault user editing a secret that was shared with them
- **WHEN** the Edit secret dialog opens
- **THEN** no drop zone is shown
