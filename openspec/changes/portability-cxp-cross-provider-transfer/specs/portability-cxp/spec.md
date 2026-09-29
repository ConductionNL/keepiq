## ADDED Requirements

### Requirement: Cross provider transfer

The system MUST let a user create a CXP import request that another provider can answer without a Keepiq relay, and MUST accept the sealed CXF response as a file. The system MUST also let a user seal an export to a request produced by another provider and download it. Only the recipient's public key from the request MUST be used to seal; the sealed file MUST NOT be readable by Keepiq or the relay.

#### Scenario: A user receives from another provider

- **GIVEN** a user on the secret list choosing Encrypted transfer and Receive from another provider
- **WHEN** the user downloads the request file, has the other provider seal an export to it and loads the response file
- **THEN** the import wizard opens with the received items

#### Scenario: A user sends to another provider

- **GIVEN** a user with a request file from another provider
- **WHEN** the user loads it, chooses one folder and confirms
- **THEN** a sealed file is downloaded that contains only that folder's items

#### Scenario: A tampered response is refused

- **GIVEN** a user loading a response file whose envelope was altered
- **WHEN** the file is opened
- **THEN** the dialog says the file could not be verified and imports nothing

### Requirement: Choose what to send

The send side MUST let the user pick all items, one folder or a selection, and the sealed file MUST contain only that choice.

#### Scenario: A selection is sealed

- **GIVEN** a user with three items selected on the list
- **WHEN** the user starts Send and confirms
- **THEN** the sealed file holds exactly the three items
