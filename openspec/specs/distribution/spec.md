# distribution Specification

## Purpose
TBD - created by archiving change fix-manifest-license. Update Purpose after archive.
## Requirements
### Requirement: Manifest Licence Declaration Consistency

The app's Nextcloud manifest (`appinfo/info.xml`) `<licence>` element MUST declare the same licence as the canonical `LICENSE` file and `composer.json`. Keepiq is licensed under **EUPL-1.2** — evidenced by the `LICENSE` file (European Union Public Licence v. 1.2), `composer.json` (`"license": "EUPL-1.2"`), the README licence badge and statement, and the `@license EUPL-1.2` SPDX tag in every `lib/` PHP docblock — so the manifest MUST use the Nextcloud app-store licence code `EUPL-1.2` (the SPDX identifier that is a member of the Nextcloud `app-info.xsd` licence enumeration; it requires `<nextcloud min-version>` ≥ 31, already satisfied by Keepiq's `min-version="31"`). The short `eupl` code is NOT a member of the schema's licence enumeration and MUST NOT be used. The manifest MUST NOT declare `agpl` or any other licence code that contradicts the `LICENSE` file.

No published licence declaration across the packaged artefacts (`appinfo/info.xml`, `composer.json`, `LICENSE`, `README.md`) may name a licence other than EUPL-1.2.

#### Scenario: Manifest matches the canonical licence

- **WHEN** the app manifest is packaged and published to the Nextcloud app store
- **THEN** the `appinfo/info.xml` `<licence>` element MUST read `EUPL-1.2`
- **AND** it MUST match the EUPL-1.2 `LICENSE` file and the `composer.json` `"license"` field

#### Scenario: No contradictory licence declaration remains

- **WHEN** any packaging or licence-of-record file (`appinfo/info.xml`, `composer.json`, `LICENSE`, `README.md`) is inspected for the project licence
- **THEN** every declaration MUST name EUPL-1.2
- **AND** no `agpl` (or otherwise divergent) licence declaration MUST remain in the manifest

