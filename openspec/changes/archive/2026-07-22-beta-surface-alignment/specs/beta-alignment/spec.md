## ADDED Requirements

### Requirement: Public surfaces SHALL declare the correct license

`appinfo/info.xml` SHALL declare `<licence>EUPL-1.2</licence>`, matching the `@license EUPL-1.2` tag present in every PHP file's docblock across `lib/`.

#### Scenario: info.xml license matches code license headers

- **GIVEN** any PHP file under `lib/`
- **WHEN** its docblock `@license` tag is compared to `appinfo/info.xml`'s `<licence>` element
- **THEN** both declare EUPL-1.2, not AGPL

### Requirement: Product page and docs SHALL only claim code-verified features

The product page (`conduction-website/src/pages/apps/doriath.mdx` and its `nl` i18n counterpart) and the docs site (`docs/`) SHALL NOT assert a feature, dependency, or architectural claim that cannot be traced to a specific file under `lib/` or `src/`.

#### Scenario: no hard OpenRegister dependency claim

- **GIVEN** `docs/tutorials/user/01-first-launch.md` and `docs/tutorials/admin/01-admin-settings.md`
- **WHEN** their prerequisites sections are read
- **THEN** neither claims OpenRegister is a hard dependency for vault storage, because `lib/Repair/InitializeSettings.php` calls `isOpenRegisterAvailable()` and skips gracefully when absent, and `docs/ARCHITECTURE.md` documents Doriath owning all its own database tables (ADR-001 exception)

#### Scenario: no unimplemented audit-log or emergency-access claim

- **GIVEN** the product page and all docs files
- **WHEN** searched for "audit log" or "emergency access" as a present-tense capability
- **THEN** no such claim exists, because both are unimplemented OpenSpec proposals (`openspec/changes/add-secret-audit-trail/`, `openspec/changes/emergency-access/`) not present on `development` HEAD

#### Scenario: no fabricated "team vault" entity claim

- **GIVEN** the product page and docs
- **WHEN** they describe sharing
- **THEN** they describe per-user vaults plus user/group/link sharing and ownership delegation — not a separate "team vault" entity, since no such entity exists in `lib/Db/`

### Requirement: The credential-broker relationship with OpenRegister SHALL be scoped to what is verified

Any claim that Doriath integrates with another Conduction app SHALL be scoped to a traceable code reference.

#### Scenario: OpenRegister pairing traces to DoriathCredentialStore

- **GIVEN** the product page's "Pairs well with OpenRegister" claim
- **WHEN** traced to code
- **THEN** it resolves to `OCA\OpenRegister\Service\Credential\DoriathCredentialStore`, which uses Doriath's `SecretService`/`EncryptService`/`DecryptService` as the storage leaf for OpenRegister's credential broker

#### Scenario: no unverified OpenConnector pairing claim

- **GIVEN** the product page
- **WHEN** checked for an OpenConnector integration claim
- **THEN** none is present, because no class under `openconnector/lib` references any Doriath credential class
