## ADDED Requirements

### Requirement: Use of a privileged login needs an approved time window

The owner of a login MUST be able to mark it as privileged. A recipient of a privileged login MUST then file an access request stating a start, an end and a reason before the server serves their copy. The owner MUST be notified of the request and MUST be able to approve it, optionally narrowed to a shorter window and to one or more IP address ranges (IPv4 or IPv6 CIDR), or deny it. Outside an approved window, or from an address outside an approved range, the server MUST refuse to return the recipient's ciphertext through the web app, the browser extension, the CLI and every other client, and MUST record the refusal in the audit trail. Every request, approval, denial and use MUST be audited with the actor, the window and the address. The owner keeps full access at all times. The check limits who receives the ciphertext; it cannot take back a value a recipient already decrypted, so after a window ends the owner MUST be offered to rotate the login.

#### Scenario: A colleague gets two hours on the production database

- **GIVEN** an owner who marked the shared login "Production database" as privileged, and a colleague holding a copy
- **WHEN** the colleague asks for access from 14:00 to 16:00 today with the reason "incident 4711" and the owner approves it for the office range `192.0.2.0/24`
- **THEN** the colleague can open and fill the login from an address in that range between 14:00 and 16:00
- **AND** the audit trail records the request, the approval and each use

#### Scenario: Use outside the window is refused

- **GIVEN** an approved window that ended an hour ago
- **WHEN** the colleague opens the login in the web app
- **THEN** the server returns no ciphertext and the detail panel offers to ask for access again
- **AND** the audit trail records the refusal

#### Scenario: Use from another address is refused

- **GIVEN** an approved window limited to `192.0.2.0/24`
- **WHEN** the colleague fills the login from the browser extension at home, outside that range
- **THEN** the server returns no ciphertext and the extension says the login is not available from this address
