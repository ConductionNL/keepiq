# cxp-transfer

## ADDED Requirements

### Requirement: Client-side HPKE seal and open

The system SHALL perform all CXP payload encryption and decryption using HPKE (RFC 9180) **entirely in the browser** (ADR-003). The importing side SHALL generate an ephemeral recipient keypair whose private key never leaves the browser session and is discarded after the payload is opened. The server, and any relay used to shuttle the handshake, SHALL only ever see public keys and HPKE ciphertext — never plaintext credential material or a private key capable of opening the envelope.

#### Scenario: Sealed payload is opened only in the browser

- **GIVEN** an HPKE-sealed CXF envelope addressed to Doriath's ephemeral public key
- **WHEN** Doriath receives it
- **THEN** the envelope MUST be HPKE-opened in the browser with the ephemeral private key
- **AND** no plaintext credential material and no opening private key MUST be transmitted to the server or any relay

#### Scenario: Suite or version mismatch fails fast

- **GIVEN** a sealed envelope whose HPKE suite or CXP version does not match the pinned transport parameters
- **WHEN** the browser attempts to open it
- **THEN** it MUST fail fast with a suite/version-mismatch error rather than attempting to mis-decrypt

### Requirement: Doriath as importing provider

The system SHALL let Doriath act as the importing provider: generate an ephemeral keypair, produce a CXP request carrying its public key, receive the HPKE-sealed CXF payload, decrypt it client-side, and feed the resulting CXF document into the existing `cxf-import-export` import pipeline (mapping preview, folder mapping, duplicate detection, chunked encrypted commit, unmapped-item report, summary). No plaintext file SHALL be written to disk at any point in the flow.

#### Scenario: Sealed CXF imports without a plaintext file

- **GIVEN** a cooperating exporting provider and a Doriath CXP import request
- **WHEN** Doriath receives and opens the sealed CXF payload
- **THEN** the decrypted CXF document MUST flow through the existing CXF import pipeline
- **AND** no plaintext `.cxf` or intermediate credential file MUST be written to disk

#### Scenario: Unrepresentable entities are reported, not dropped

- **GIVEN** a sealed CXF payload containing an entity Doriath cannot represent
- **WHEN** the import runs
- **THEN** the entity MUST appear in the existing import unmapped-item / rejected-rows report with a reason, exactly as file-based CXF import handles it

### Requirement: Doriath as exporting provider

The system SHALL let Doriath act as the exporting provider: receive a CXP request carrying the requester's public key, gate the export with the existing fresh master-password re-authentication, assemble the CXF export client-side via the existing `cxf-import-export` export path, HPKE-seal the assembled payload under the requester's public key in the browser, and return only the sealed envelope. No plaintext file SHALL be written to disk at any point in the flow.

#### Scenario: Export is re-auth gated before sealing

- **GIVEN** an incoming CXP request and an already-unlocked vault
- **WHEN** Doriath begins assembling the CXF export
- **THEN** the system MUST require fresh master-password re-authentication before assembling and sealing

#### Scenario: Only a sealed envelope leaves Doriath

- **GIVEN** a re-authenticated CXP export
- **WHEN** Doriath assembles the CXF payload
- **THEN** the payload MUST be HPKE-sealed under the requester's public key in the browser and only the sealed envelope MUST be handed back
- **AND** no plaintext `.cxf` or intermediate credential file MUST be written to disk

### Requirement: CXP transfer emits an export event with mode cxp

The system SHALL report a CXP export to the existing export-event endpoint so that a `SecretExportedEvent` is emitted with transfer mode `cxp`, carrying no secret names, values, or ciphertext — reusing the `secret-export` event contract and distinguishing the sealed transfer from a plaintext-file `cxf` export only by mode.

#### Scenario: Event records the sealed transfer without secret material

- **GIVEN** a completed CXP export
- **WHEN** the export-event endpoint is called
- **THEN** a `SecretExportedEvent` with mode `cxp` MUST be emitted
- **AND** the event payload MUST contain no secret names, values, or ciphertext

### Requirement: v1 scope is the browser-session flow

The system SHALL implement the CXP request/response within a Doriath browser session against a cooperating provider in v1, and SHALL NOT depend on native OS or platform credential-provider integration. When no cooperating CXP provider is available, the system SHALL leave the existing file-based CXF path available as the fallback.

#### Scenario: Falls back to file-based CXF when no CXP peer exists

- **GIVEN** a target provider that offers only file-based CXF
- **WHEN** the user attempts a migration
- **THEN** CXP MUST NOT be forced, and the existing file-based CXF import/export path MUST remain available as the fallback
