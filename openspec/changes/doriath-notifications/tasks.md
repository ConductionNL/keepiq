# Tasks — doriath notifications

- [ ] PREREQUISITE: declare `application`, `share`, `secret-request`, `secret`, `encryption-suite` as OpenRegister schemas in lib/Settings/doriath_register.json (blocking change `doriath-schemas-in-register-json` — file the issue at planning time)
- [ ] Add `x-openregister-notifications` (rule `app-pending-approval`, created+filter status=pending) to `application` in lib/Settings/doriath_register.json
- [ ] Add `x-openregister-notifications` (rule `share-received`, created) to `share` in lib/Settings/doriath_register.json
- [ ] Add `x-openregister-notifications` (rule `secret-request-incoming`, created) to `secret-request` in lib/Settings/doriath_register.json
- [ ] Add `x-openregister-notifications` (rule `secret-request-fulfilled`, updated field-change, depends on engine gap, enabled:false) to `secret-request` in lib/Settings/doriath_register.json
- [ ] Add `x-openregister-notifications` (rule `secret-compromised`, updated field-change, depends on engine gap, enabled:false) to `secret` in lib/Settings/doriath_register.json
- [ ] Add `x-openregister-notifications` (rule `ca-expiry`, scheduled, enabled:false) to `encryption-suite` in lib/Settings/doriath_register.json
- [ ] Re-check every rule's recipient `field` against the real declared property names; drop or remap any field that does not exist
- [ ] Add nl + en `subject` strings to every rule (already specified in proposal.md), metadata-only — no secret values
- [ ] Validate the register JSON still parses (e.g. `python3 -c "import json;json.load(open('lib/Settings/doriath_register.json'))"`)
- [ ] Retire the corresponding imperative `NotificationService` dispatch (e.g. `app_pending`) when the declarative rule goes live, to avoid double-notifying

## Acceptance criteria

- The mandated schemas exist in the register JSON before any rule is applied (hard blocker).
- The register JSON parses and every touched schema keeps its existing keys intact.
- `created`-based rules (`app-pending-approval`, `share-received`, `secret-request-incoming`) work today once schemas exist; status-change rules depend on `notification-updated-field-change-condition` and ship disabled.
- Every rule's recipient `field` references a property that actually exists on its declared schema.
- Every rule has both `nl` and `en` subject strings, and no subject/body carries secret values, keys, or login material (security MUST).
- Scheduled `ca-expiry` ships disabled until the engine's relative-date filter is confirmed.
