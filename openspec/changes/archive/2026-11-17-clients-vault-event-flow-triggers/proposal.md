---
kind: code
---

# Start a flow when something happens in the vault

## Why

The Flows menu item opens OpenRegister's flow list and canvas (`src/manifest.json:207`, page `Flows` at `:658`), shown only when OpenRegister is installed. A flow there can start on an OpenRegister object, a file, a user, a share, a tag, a schedule or a manual run (`openregister lib/Service/Flow/EventCatalogService.php`). Keepiq keeps its vault in its own tables (ADR-001, ADR-004) and fires nothing into that engine. So an administrator can open the builder, but no vault event can start a flow: a share that is granted, a link that is opened, an emergency access request, an application that waits for approval.

Every one of those events already exists. Keepiq dispatches a typed `AuditEvent` for each of them (`lib/Event/Audit/AuditEventTypes.php`), and the SIEM export already turns it into a payload without secret material (`lib/Service/SiemService.php::buildPayload()`). This change hands that same payload to the flow engine.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `clients-15` | Automate follow-up work on vault events with a flow builder. | `no`: the Flows page is reachable, but no vault event can start a flow |

## What Changes

- **Vault events become flow triggers.** When OpenRegister is enabled, a new listener on `AuditEvent` fires `keepiq.<event type>` into OpenRegister's `FlowTriggerService::fire()`. The payload is the SIEM payload: who, what kind of event, which item id, when. No secret value, name, username or address is in it.
- **The builder lists the vault triggers.** Keepiq contributes its trigger ids, labels and a `Vault` group to the flow builder's trigger palette, so an administrator picks "A share is granted" instead of typing an id.
- **An administrator chooses which events may start flows.** A setting in the Keepiq admin section lists the trigger groups (items, sharing, links, requests, emergency access, applications, keys) and starts with all of them off.
- **Keepiq stays usable alone (ADR-006).** Without OpenRegister nothing is registered and nothing is fired. No Keepiq class references an `OCA\OpenRegister\…` class unless OpenRegister is running.

## Capabilities

### New Capabilities

- `vault-event-flow-triggers`: vault events start OpenRegister flows with a metadata-only payload.

### Modified Capabilities

- None. `siem-audit-export` keeps its payload contract; this change reads it and does not change it.

## Impact

- **Backend**: `lib/Listener/FlowTriggerForwardListener.php`, `lib/Service/Flow/VaultFlowTriggerCatalog.php`, a binding in `lib/AppInfo/AuditStreamEventRegistrar.php`, one admin setting.
- **Frontend**: one section in the Keepiq admin settings (KqBeheerAudit). The flow list and canvas stay OpenRegister's.
- **Database**: none. The setting is an app config value.
- **Security**: the payload is the SIEM payload, which a test already pins as free of secret material. A flow cannot read a secret, because the server never holds one in plain text.
- **Cross-app**: OpenRegister needs one extension point: a way for an app to add entries to the trigger catalog. Today the catalog is a constant (`EventCatalogService::CATALOG`). This is an OpenRegister task, tracked below; until it lands the triggers still fire (an unknown id matches only itself in `aliasesFor()`), but the palette does not list them.
