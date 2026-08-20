## ADDED Requirements

### Requirement: Vault Data Never Mirrors Into Leaf-Backed Stores
The system MUST NOT copy, mirror, or link secret material into OpenRegister objects or into any store that an integration leaf renders from. This covers ciphertext (`key`, `login`, `additional_fields` blobs) AND vault-structure metadata: entry names, URLs, folder structure, expiry dates, certificate subjects, and rotation state. Vault-structure metadata is access-controlled by Doriath's own model (encryption suites, shares, delegation, team folders); replicating it into calendar/files/deck/activity stores places it under those apps' independent sharing and sync semantics, outside the vault's ACL envelope. This requirement binds all future changes: it is the reason the leaves below are refused, not a summary of them.

#### Scenario: No vault data reaches OpenRegister
- **WHEN** the OpenRegister object store is inspected on an instance with a populated Doriath vault
- **THEN** no OR object contains a secret value, entry name, folder path, expiry date, or certificate identity originating from the vault
@e2e exclude documented-decision boundary; asserted by the register-shape guard test (tests/unit/Settings/RegisterLeafGuardTest.php) and code review — no runtime surface exists to exercise.

#### Scenario: A sync or export path cannot widen access
- **WHEN** any Doriath feature hands data to another Nextcloud app's storage
- **THEN** the receiving surface MUST NOT allow a principal to learn vault contents or structure they could not already access through Doriath's own authorization

### Requirement: No Integration Leaf Is Declared
No Doriath register schema (in `lib/Settings/doriath_register.json` or any future `register.d/` fragment) may declare `configuration.linkedTypes` or `configuration.mailObjectTemplate`. The considered leaves are individually refused:

- **files** — a files leaf would place secret material or backup artifacts under NC Files sharing; the export flows (secret-export spec) already define the only sanctioned file egress, client-side and user-initiated.
- **calendar** — certificate/secret expiry as CalDAV items would replicate entry names + dates (a map of the organisation's credentials and their rotation windows) into calendars with independent sharing, sync clients, and exports; expiry visibility is already served in-app (see the next requirement).
- **deck / activity** — rotation follow-up cards or activity streams would copy entry names onto surfaces with their own sharing; the rotation-flag queue (rotation-expiry-policies spec) is the in-app equivalent.

A guard test MUST assert the absence of leaf configuration in the register JSON so a fleet-wide codemod cannot flip this decision silently.

#### Scenario: Register carries no leaf configuration
- **WHEN** `doriath_register.json` and every `register.d/*.json` fragment are scanned
- **THEN** no schema carries `linkedTypes` or `mailObjectTemplate`
- **AND** the guard test fails if either key appears
@e2e exclude static configuration assertion; covered by PHPUnit (tests/unit/Settings/RegisterLeafGuardTest.php).

#### Scenario: A leaf-bearing fragment is rejected in review
- **WHEN** a change proposes adding `linkedTypes` to a Doriath schema without modifying this capability
- **THEN** the guard test fails and the change MUST be rejected until this spec's requirements are explicitly modified

### Requirement: Expiry And Rotation Visibility Stays In-App
Certificate and secret expiry awareness MUST continue to be served by Doriath's own surfaces — the expiry scan jobs (`ScanCertificateExpiryJob`, `CheckRootCertificateExpiry` with 90/30/7-day thresholds, `ScanExpiringSecretsJob` with policy reminder thresholds), Nextcloud notifications, rotation flags, and the dashboard — and MUST NOT be re-implemented as a calendar leaf or external feed. These surfaces answer under the vault's own ACL: a user is notified only about entries they can access.

#### Scenario: Expiring certificate surfaces without leaving the app
- **WHEN** a certificate crosses a notification threshold before its `notAfter`
- **THEN** the owner receives a Nextcloud notification from the existing scan pipeline
- **AND** no calendar object, feed, or cross-app artifact is produced
@e2e exclude existing background-job behaviour restated as a boundary; covered by the expiry-scan PHPUnit suites, no new surface.

### Requirement: Future Leaf Adoption Must Pass The Exception Gate
Any future change that surfaces Doriath data through an integration leaf MUST (1) modify this capability explicitly, (2) carry only data that is neither secret material nor vault-structure metadata as defined above — or prove the receiving surface enforces an ACL at least as narrow as the vault's, (3) be scoped to the owner's own session/principal, and (4) name the specific leaf and data fields in its spec delta. Absent all four, the leaf MUST NOT ship.

#### Scenario: A metadata-only proposal is evaluated against the gate
- **WHEN** a change proposes surfacing rotation-due counts (numbers only, no entry names) on a dashboard leaf
- **THEN** the change is only acceptable if it modifies this capability and demonstrates conditions (2) and (3) hold for the exact fields shipped
