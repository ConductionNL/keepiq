## ADDED Requirements

### Requirement: A demo vault is minted only for a named cast account

The system MUST mint a vault with the demo master password only for an account named in `demo_vault_owners` when `demo_vault_master_password` is set, the account exists, is not an administrator and has no encryption suite of any status. It MUST NOT change, extend or replace an existing suite.

#### Scenario: The demo employee gets a vault

- **GIVEN** the example set wrote `demo_vault_owners = pieter` and a demo master password
- **AND** pieter has no vault
- **WHEN** the repair steps run
- **THEN** pieter has one active suite that opens with the demo master password

#### Scenario: An existing vault is left alone

- **GIVEN** pieter already has a vault
- **WHEN** the repair steps run
- **THEN** pieter's suites and secrets are unchanged

#### Scenario: An administrator is refused

- **GIVEN** `demo_vault_owners` names an administrator
- **WHEN** the repair steps run
- **THEN** no suite is minted for that account

### Requirement: A demo vault holds only demo secrets

A demo vault MUST hold only the folders and secrets of `lib/Settings/demo-vault.json`, and every secret value there MUST be made up and say it is a demo value.

#### Scenario: The demo vault lists the demo secrets

- **GIVEN** pieter's demo vault was just minted
- **WHEN** pieter unlocks it with the demo master password
- **THEN** the vault lists the demo secrets in the folders Gemeentebreed, Koppelingen and Webbeheer
