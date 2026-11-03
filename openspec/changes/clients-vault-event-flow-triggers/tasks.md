# Tasks: start a flow when something happens in the vault

## 0. OpenRegister prerequisite (owned by ConductionNL/openregister)

- [ ] 0.1 Open an OpenRegister change for `RegisterFlowTriggersEvent`: dispatched when `EventCatalogService` builds its catalog, carrying `addTrigger(string $id, string $label, string $group)`. `getCatalog()` and `knownTriggerIds()` return the constant entries plus the contributed ones. Verify: an OpenRegister unit test that a contributed trigger shows in `GET /api/flow/event-catalog`.

## 1. Fire vault events

- [ ] 1.1 Add `lib/Service/Flow/VaultFlowTriggerCatalog.php`: maps each `AuditEventTypes` constant to a trigger id (`keepiq.` + type), a label and a group (items, item reads, sharing, links, requests, emergency access, applications, keys). Verify: unit test that every constant in `AuditEventTypes` has exactly one entry.
- [ ] 1.2 Add `lib/Listener/FlowTriggerForwardListener.php` on `AuditEvent`: skip when OpenRegister is not enabled or the group is off; build the payload with `SiemService::buildPayload()`; resolve `OCA\OpenRegister\Service\Flow\FlowTriggerService` from the container by string; call `fire(event, [], user, ['payload' => $payload])`; catch and log every `Throwable`. Verify: unit test with the real `AuditEvent` class, a stub trigger service, and assertions for group off, OpenRegister off, and a throwing service.
- [ ] 1.3 Bind the listener in `lib/AppInfo/AuditStreamEventRegistrar.php` next to `SiemForwardListener`. Verify: a test that asserts the binding from the registrar, not from the listener.
- [ ] 1.4 Listen for `RegisterFlowTriggersEvent` (string class name, guarded by `class_exists`) and add the catalog entries with group `Vault`. Verify: unit test with a fake registry.

## 2. Admin setting

- [ ] 2.1 Store enabled groups in app config `flow_trigger_groups` (JSON array, default empty); expose read and write on the existing admin settings endpoint, admin only. Verify: controller test that a non-admin gets 403 and an admin round-trips the value.
- [ ] 2.2 Add a Flows section to the audit admin page (board KqBeheerAudit): one checkbox per group, the sentence "Flows receive who did what and when, never a secret.", and the section hidden when OpenRegister is not enabled. Verify: vitest for hidden and shown, and a Playwright check on the admin page.

## 3. End to end

- [ ] 3.1 With OpenRegister enabled, publish a flow on `keepiq.share.granted`, enable the sharing group, share a login, and assert one run in the flow's run list with a payload free of secret material. Verify: Playwright or Newman against the dev stack.

## 4. Docs

- [ ] 4.1 Document the trigger list, the groups and the payload in `docs/flows.md`. Verify: docs build.

## Acceptance criteria

- An administrator can start a flow on any audited vault event, per enabled group.
- The flow receives the SIEM payload and no secret material.
- Keepiq works unchanged on a Nextcloud without OpenRegister.
