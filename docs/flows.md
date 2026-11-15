<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Flows on vault events

When OpenRegister is installed, an audited vault event can start a flow on the Flows page: a share that is granted, a link that is opened, an emergency access request, an application that waits for approval.
Keepiq hands the flow the same metadata a SIEM sink gets: who did what to which item, and when. Never a secret value, an item name, a username or an address.

Without OpenRegister nothing is registered with a flow engine, nothing is fired and the Flows section is not shown.

## Switching groups on

Every group starts switched off, so a fresh install fires nothing.
An administrator switches groups on in the Keepiq admin settings, under **Audit**, section **Flows**. The choice is stored in the app config value `flow_trigger_groups` (a JSON list) and is part of the audit area settings (`GET` / `PUT /apps/keepiq/api/settings/admin/audit`).

| Group | Key | Events |
|---|---|---|
| Items | `items` | items, folders, attachments, leases, vault exports and backups, policies, compliance reports |
| Item reads | `item_reads` | an item revealed or used, an attachment downloaded, a honey item touched (fires on every reveal) |
| Sharing | `sharing` | shares, team folders, federated shares and federation partners |
| Links | `links` | link shares |
| Requests | `requests` | secret requests |
| Emergency access | `emergency_access` | emergency access and account recovery |
| Applications | `applications` | applications, MCP tools and SIEM sinks |
| Keys | `keys` | encryption suites, key proofs, passkeys, certificates and device approvals |

## Triggers

Each audit event type fires as `keepiq.` plus the type, for example `keepiq.share.granted` or `keepiq.emergency_access.requested`.
The flow builder lists them under the group **Vault** with a plain label ("A share is granted") once OpenRegister offers its trigger catalog extension; until then a flow stored with a `keepiq.*` trigger still matches when the event fires.

## Payload

A flow is started with an empty subject and the SIEM payload as `context.payload`:

```json
{
  "eventType": "share.granted",
  "category": "share",
  "actorType": "user",
  "actorId": "alice",
  "objectType": "secret",
  "objectId": "42",
  "occurredAt": "2026-11-14T09:30:00+00:00",
  "metadata": { "recipientType": "user", "recipientId": "bob" }
}
```

The metadata holds only the keys the SIEM export allows for that event type. A failure in the flow engine is written to the Nextcloud log and never blocks the vault action that caused it.
