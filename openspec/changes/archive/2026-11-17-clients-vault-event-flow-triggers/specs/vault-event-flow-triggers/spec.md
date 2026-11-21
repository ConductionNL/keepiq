## ADDED Requirements

### Requirement: Vault events fire flow triggers

When OpenRegister is enabled and an administrator has enabled the event's trigger group, the system MUST fire every audited vault event into OpenRegister's flow trigger service under the id `keepiq.<audit event type>`, with an empty subject and the SIEM payload of that event as `context.payload`. The payload MUST NOT contain a secret value, a decrypted name, a username or an address. A failure to fire MUST be logged and MUST NOT break the audited action.

#### Scenario: A granted share starts a flow

- **GIVEN** OpenRegister is enabled and the sharing group is enabled in Keepiq's admin settings
- **AND** a published flow has the trigger `keepiq.share.granted`
- **WHEN** a vault user shares a login with a colleague
- **THEN** OpenRegister queues one run of that flow
- **AND** the run's payload carries the acting user, the recipient, the item id and the time
- **AND** the payload carries no secret value and no item name

#### Scenario: A broken flow engine does not block a share

- **GIVEN** OpenRegister is enabled and its flow trigger service throws
- **WHEN** a vault user shares a login with a colleague
- **THEN** the share is saved
- **AND** the failure is written to the Nextcloud log

### Requirement: Administrators choose which event groups start flows

The system MUST offer administrators a Flows section in the Keepiq audit administration (board KqBeheerAudit) with one switch per trigger group: items, item reads, sharing, links, requests, emergency access, applications and keys. Every group MUST start switched off. An event in a group that is switched off MUST NOT be fired. The section MUST state that flows receive who did what and when, and never a secret.

#### Scenario: A fresh install fires nothing

- **GIVEN** a fresh Keepiq install with OpenRegister enabled
- **WHEN** a vault user creates a login
- **THEN** no `keepiq.*` trigger is fired

#### Scenario: An administrator enables the emergency access group

- **GIVEN** an administrator on the Keepiq audit administration
- **WHEN** the administrator switches on Emergency access and saves
- **THEN** the next emergency access request fires `keepiq.emergency_access.requested`
- **AND** a login created afterwards still fires nothing

### Requirement: The flow builder lists vault triggers

When OpenRegister is enabled, the system MUST contribute every Keepiq trigger id to OpenRegister's flow trigger catalog with a plain-sentence label and the group `Vault`, so the trigger palette of the flow builder shows them.

#### Scenario: An administrator picks a vault trigger

- **GIVEN** OpenRegister is enabled and offers the trigger catalog extension
- **WHEN** an administrator opens a new flow on the Flows page and opens the trigger palette
- **THEN** the palette shows a Vault group with "A share is granted"

### Requirement: Keepiq runs without OpenRegister

When OpenRegister is not installed or not enabled, the system MUST NOT register the flow trigger listener or the catalog contribution, MUST NOT load any `OCA\OpenRegister\…` class, and MUST NOT show the Flows section in the admin settings.

#### Scenario: A vault on a plain Nextcloud

- **GIVEN** a Nextcloud instance without OpenRegister
- **WHEN** a vault user shares a login
- **THEN** the share is saved and nothing is logged about flows
- **AND** the Keepiq audit administration shows no Flows section
