# Design: start a flow when something happens in the vault

## Context

At development `7c107990`:

- `lib/AppInfo/AuditStreamEventRegistrar.php:74,83` binds the audit log writer and `SiemForwardListener` to `AuditEvent`. The SIEM listener is fail-soft: a failure is logged and never breaks the audited action (`lib/Listener/SiemForwardListener.php:64-83`).
- `lib/Service/SiemService.php:112` `buildPayload()` turns an `AuditEvent` into a metadata-only array, or null when the event is not forwarded.
- `lib/Event/Audit/AuditEventTypes.php` holds the event type constants (`secret.created`, `share.granted`, `link_share.accessed`, `emergency_access.requested`, `application.registered`, `vault.exported`, and so on).
- OpenRegister `lib/Service/Flow/FlowTriggerService.php:86` `fire(string $event, array $subject, ?string $user, array $context)` queues a run for every flow wired to the event and never throws into the caller. `NativeFlowTriggerListener` calls it with an empty subject and `['payload' => …]` as context for file, user and share events.
- OpenRegister `EventCatalogService::CATALOG` is a constant. `aliasesFor()` returns an unknown id as itself, so a flow stored with a `keepiq.*` trigger matches when that id is fired.
- OpenRegister `RegisterFlowNodesEvent` is the existing pattern for apps contributing to the flow engine (node types).
- ADR-006: OpenRegister is optional; the Flows page is shown only when it is installed (`src/manifest.json:213`).

## Screens

- **Flow list and editor**: the shared library pages, drawn as `PqFlows` and `PqFlow` on the canvas (5NkFW28vZUUij43xzxHg5a). The inventory (`inventory-keepiq.md`) records Flows as a page every app has, not a Keepiq board. A Keepiq flow shows its trigger in the Trigger column of `PqFlows` and in the header line of `PqFlow` ("trigger A share is granted · 3 steps"), with the label this change contributes.
- **The admin switch**: `KqBeheerAudit`, the audit area of Keepiq's admin section, next to the SIEM sinks that read the same events. A section "Flows" with one checkbox per trigger group and the sentence "Flows receive who did what and when, never a secret."

## Goals / Non-Goals

**Goals**
- Any vault event that is audited can start a flow, with the same metadata the SIEM gets.
- An administrator decides which groups of events may do so.

**Non-Goals**
- A Keepiq flow engine or flow page. OpenRegister's engine is the only one (hydra ADR-110).
- Flow steps that read or write secrets. A step that needs a secret value cannot exist under zero knowledge.
- Per-user flows on a personal vault. Flows are an administrator tool.

## Decisions

**D1. Reuse the SIEM payload.** The listener calls `SiemService::buildPayload()` and passes the result as `context.payload`. Alternative: a second, flow-specific payload. Rejected: two payload builders drift, and the SIEM one is already tested to carry no secret material.

**D2. Trigger ids are `keepiq.` plus the audit type.** `share.granted` fires as `keepiq.share.granted`. The prefix keeps them apart from OpenRegister's own `share.created`. Labels come from a Keepiq catalog class in plain sentences ("A share is granted").

**D3. Subject is empty.** Vault items are not OpenRegister objects, so `fire()` gets `subject: []`, like the native file and user triggers. The item id is in the payload.

**D4. Off by default, per group.** App config `flow_trigger_groups` holds the enabled groups; empty means nothing fires. An administrator turns groups on in KqBeheerAudit. `secret.read` is in its own group, because it fires on every reveal and can flood the queue.

**D5. Guarded by app state.** The listener and the catalog contribution are registered only when `IAppManager::isEnabledForUser('openregister')` is true at boot, and the listener checks again before firing. No `OCA\OpenRegister\…` type appears in a Keepiq signature; the service is fetched from the container by class name string inside a try block.

**D6. Catalog extension lives in OpenRegister.** OpenRegister adds `RegisterFlowTriggersEvent`, dispatched when the catalog is built, with `addTrigger(id, label, group)`. Keepiq listens and contributes its entries. Until that lands, D2 ids still fire and match stored flows.

## Risks

- **Queue load.** A busy vault fires many events. Mitigation: off by default, `secret.read` separate, and OpenRegister drops events with no wired flow before queueing (`flowsForTrigger()` returns empty).
- **Information in flows.** A flow step can e-mail the payload. The payload holds user ids and item ids. Mitigation: the admin section says so, and the payload is the same one already sent to a SIEM.
