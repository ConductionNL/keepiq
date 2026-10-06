# ADR-006: Keepiq is independently usable and runs its own app shell

**Status**: accepted

**Date**: 2026-10-05

- **References:** hydra ADR-040 (AppHost engine), hydra ADR-022 (apps consume OpenRegister abstractions), hydra ADR-110 (one flow engine), hydra ADR-006 (observability postures)
- **Change:** `openspec/changes/standalone-app-shell`

## Context

Keepiq keeps every secret in its own encrypted tables (ADR-001, ADR-004) and
stores nothing in OpenRegister. Its app shell did run on OpenRegister's AppHost
engine (hydra ADR-040, adopted in `2026-06-16-adopt-apphost`): the startup
wiring, the route table, the admin section, health, metrics and preferences all
came from `OCA\OpenRegister\AppHost\…`, and the frontend mounted `CnAppRoot`
with its default `requiresApps: ['openregister']`.

So a vault that needs nothing from OpenRegister could not be used without it.
#857 and #867 showed the worst case: a missing or disabled OpenRegister took
the whole Nextcloud instance down. #892 kept Nextcloud up, but only by
degrading Keepiq, and its README had to say Keepiq "is not usable" without
OpenRegister. Keepiq's releases were also tied to OpenRegister's: an
OpenRegister that failed to upgrade on a new Nextcloud blocked Keepiq too.

## Decision

Keepiq is independently usable: it installs, upgrades and works fully on a
Nextcloud instance with no other Conduction app.

- Keepiq does **not** adopt the AppHost engine (exception to hydra ADR-040)
  and does **not** consume OpenRegister abstractions for its shell (exception
  to hydra ADR-022). It runs its own startup wiring, a static route table,
  a native admin section, and plain `HealthController`, `MetricsController`
  and `PreferencesController` classes with the contracts the `app-shell`
  capability pins.
- Integrations that genuinely need another app stay, and appear only when
  that app is enabled:
  - the MCP tools and the Flows pages need OpenRegister. OpenRegister's flow
    engine stays the only flow engine (hydra ADR-110); Keepiq does not build
    its own;
  - the Integrations page and connection reporting need integriq;
  - the AI companion needs Hermiq.
- No Keepiq code path references an `OCA\OpenRegister\…` or
  `OCA\Integriq\…` class unless that app is running.

Agreed by the product owner and by Ruben (in person) on 2026-10-05.

## Consequences

**Positive:**
- Enabling, disabling or breaking OpenRegister no longer affects Keepiq, and
  Keepiq can ship on a new Nextcloud version without waiting for OpenRegister.
- Keepiq's controllers are real classes again, so hydra gates 5
  (route-auth) and 14 (route-reachability) judge Keepiq code rather than
  AppHost aliases.
- The dead register import, its version pin and its repair step go away.

**Negative / trade-offs:**
- A fleet-wide AppHost improvement no longer reaches Keepiq automatically.
  Keepiq maintains its own small shell instead.
- Keepiq diverges from the fleet pattern, which reviewers and agents enforce.
  This ADR is the reference for that divergence.

**Gate consequences:**

| Gate / test | Effect |
|---|---|
| gate-64 (apphost-autoload-prelude) | No longer applies: there is no AppHost call and no prelude. |
| gate-59 (config-version read without write) | Closed: `InitializeSettings` writes `config_version` itself. |
| gate-30 (public-monitoring) | Passes: metrics declares `#[AuthorizedAdminSetting]`, health `#[PublicPage]`. |
| gates 5 and 14 | Judge the real Keepiq controllers. |
| `CanonicalRouteMethodContractTest` | Rewritten to assert Keepiq's static route table instead of `Routes::standard(`. |

## Alternatives Considered

| Option | Reason not chosen |
|--------|------------------|
| Keep AppHost and make AppHost itself OpenRegister-independent | Right for the fleet, but it is a cross-app project with no date. Keepiq's independence should not wait for it. |
| Vendor the AppHost generics into Keepiq | Keeps an engine (manifest-driven observability, store, deep-link listener) for two checks, one gauge and a preferences endpoint. Plain controllers are less code. |
| Keep the #892 degraded fallback | Keepiq stays unusable without OpenRegister, which is the problem this ADR solves. |
