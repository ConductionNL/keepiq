## Why

Doriath's `lib/` and `src/` were implemented before the ADR-003 `@spec` traceability convention was enforced. 103 methods (50 backend + 53 frontend) carry real domain behavior but no `@spec` docblock annotation, so Gate-16 reports them as uncovered. Every one of those methods implements a capability already described in `openspec/specs/**` — this is a pure retrofit annotation pass, not new design.

## What Changes

- Annotate all 103 previously-uncovered methods with `@spec` references pointing at the tasks in this change, each task in turn citing the existing capability spec + REQ it realizes.
- No production code behavior changes — docblock-only additions.
- Two SPA-shell render methods (`DashboardController::page`/`catchAll`) and a small number of pure store-ref/computed passthroughs are marked `@spec exclude` as framework plumbing with zero domain logic.

## Capabilities

### Modified Capabilities

This change adds no new requirements. It maps observed, already-shipped behavior to existing capability specs:

- `encryption-suites` — suite lifecycle (create/revoke/reinstate/markCompromised/update), CA bootstrap/renewal/health/signing, crypto services (RSA/AES encrypt+decrypt, key derivation, private-key wrapping), suite migration / compromise recovery, lock-screen session mechanism.
- `admin-settings` / `user-settings` — settings load/get/update, CA health admin section, password policy section.
- `application-mgmt` — application registration via `SettingsController` create/index and seed/init repair steps.
- `dashboard` — vault summary + migration-status views (frontend stores feeding the dashboard) and admin counters.
- `secrets` — Nextcloud unified-search deep-link registration listener; object store fetch/configure.

See `tasks.md` for the method-to-REQ map. All tasks are `[x]` because the code already exists.
