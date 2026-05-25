---
kind: config
depends_on: [notification-updated-field-change-condition]
blocked_on: doriath-schemas-in-register-json
---

# doriath — schema-declared notifications

## Why

Doriath is the fleet's encrypted secrets manager. Its specs already **mandate
(MUST)** several notifications: an application pending approval → vault admins, a
share received → recipient, a secret-request incoming/fulfilled → the relevant
party, a compromise → owner, and CA expiry. Today those notifications are wired
**imperatively** (`NotificationService` + Nextcloud `IManager`, e.g.
`implement-application-mgmt` dispatches `app_pending` to admins in PHP). This
change moves those notification *intents* onto the schemas as declarative
`x-openregister-notifications` rules so the OpenRegister notification engine —
not bespoke per-feature PHP — is the single dispatch path, consistent with the
rest of the fleet.

## ⚠ Blocking precondition (read first)

**Doriath's data model is not yet declared in `lib/Settings/doriath_register.json`.**
That register currently contains only a single placeholder `example` schema. The
Application / Share / SecretRequest / Secret / EncryptionSuite entities live as
Nextcloud DB-table entities + migrations (`doriath_applications`,
`doriath_secrets`, …), authored by the `implement-*` changes. Because the
notification engine reads `x-openregister-notifications` off
`components.schemas.<Schema>` in the **register JSON**, there are **no schemas to
attach the rules to yet**.

So this change is **blocked** on a prerequisite: the relevant doriath entities
must first be declared as OpenRegister schemas in
`lib/Settings/doriath_register.json` (`application`, `share`, `secret-request`,
`secret`, `encryption-suite`). Until then the rules below are a
ready-to-drop-in design, not an applicable JSON edit. The `## What Changes`
section is written so it can be applied verbatim once the schemas exist.

This change also carries `depends_on: [notification-updated-field-change-condition]`
because the share-fulfilled / request-fulfilled / compromise rules are
status-change events (see Caveats) that need the unshipped `updated`-field-change
engine condition. The `created`-based rules (app pending, share received, request
incoming) work today once the schemas exist.

## What Changes

Once the prerequisite schemas exist, add a top-level `x-openregister-notifications`
key to each, using the verified engine dialect. **All subjects stay
metadata-only** — never include secret values, keys, or login material.

### `application` — pending approval → admins (created + filter)

```jsonc
"x-openregister-notifications": {
  "app-pending-approval": {
    "trigger": {"type": "created", "filter": {"status": {"op": "equals", "value": "pending"}}},
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [ {"kind": "groups", "groups": ["admin"]} ],
    "subject": {
      "nl": "Applicatie wacht op goedkeuring: {{name}}",
      "en": "Application pending approval: {{name}}"
    }
  }
}
```

### `share` — share received → recipient (created)

Requires a `recipientUid` (or equivalent NC-uid) field on the share schema.

```jsonc
"x-openregister-notifications": {
  "share-received": {
    "trigger": {"type": "created"},
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [ {"kind": "field", "field": "recipientUid"} ],
    "subject": {
      "nl": "Geheim met je gedeeld: {{secretName}}",
      "en": "A secret was shared with you: {{secretName}}"
    }
  }
}
```

### `secret-request` — incoming request → owner (created)

Requires an `ownerUid` (recipient of the request) field on the schema.

```jsonc
"x-openregister-notifications": {
  "secret-request-incoming": {
    "trigger": {"type": "created"},
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [ {"kind": "field", "field": "ownerUid"} ],
    "subject": {
      "nl": "Nieuw geheim-verzoek van {{requesterUid}}",
      "en": "New secret request from {{requesterUid}}"
    }
  }
}
```

### `secret-request` — request fulfilled → requester (status change) — DEPENDS ON ENGINE GAP

```jsonc
"x-openregister-notifications": {
  "secret-request-fulfilled": {
    "trigger": {"type": "updated", "condition": {"field": "status", "operator": "equals", "value": "fulfilled"}},
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [ {"kind": "field", "field": "requesterUid"} ],
    "subject": {
      "nl": "Je geheim-verzoek is ingewilligd",
      "en": "Your secret request was fulfilled"
    }
  }
}
```

### `secret` — compromise flagged → owner (status change) — DEPENDS ON ENGINE GAP

```jsonc
"x-openregister-notifications": {
  "secret-compromised": {
    "trigger": {"type": "updated", "condition": {"field": "compromised", "operator": "equals", "value": true}},
    "enabled": true,
    "channels": ["nc-notification", "email"],
    "recipients": [ {"kind": "field", "field": "ownerUid"} ],
    "subject": {
      "nl": "Mogelijk gecompromitteerd geheim: {{name}}",
      "en": "Possibly compromised secret: {{name}}"
    }
  }
}
```

### `encryption-suite` — CA expiry reminder (scheduled)

Requires a `caNotAfter` (expiry date) field and an `ownerUid` on the suite schema.

```jsonc
"x-openregister-notifications": {
  "ca-expiry": {
    "trigger": {"type": "scheduled", "intervalSec": 86400, "filter": {"caNotAfter": {"op": "lt", "value": "now+30d"}}},
    "enabled": false,
    "channels": ["nc-notification"],
    "recipients": [ {"kind": "field", "field": "ownerUid"} ],
    "subject": {
      "nl": "CA-certificaat verloopt binnenkort: {{name}}",
      "en": "CA certificate expiring soon: {{name}}"
    }
  }
}
```

## Capabilities

### New Capabilities
- `doriath-notifications`: declarative schema-level notification rules on
  `application`, `share`, `secret-request`, `secret`, and `encryption-suite`,
  consumed by the OpenRegister notification engine, surfacing pending-approval,
  share-received, request incoming/fulfilled, compromise, and CA-expiry events —
  replacing the per-feature imperative `NotificationService` dispatch with a
  single declarative path.

### Modified Capabilities
- `application-mgmt` (and the share / secret-request notification surfaces): the
  `app_pending` (and equivalent share/request) dispatch currently mandated as
  imperative `NotificationService` calls is superseded by the declarative
  `application`/`share`/`secret-request` schema rules above, once the schemas
  exist in the register JSON.

## Impact

- **File (once unblocked):** `lib/Settings/doriath_register.json` — adds
  `x-openregister-notifications` to `application`, `share`, `secret-request`,
  `secret`, `encryption-suite`. **No applicable edit today** — those schemas are
  not yet in the register JSON (see Blocking precondition).
- The OpenRegister notification engine (shipped in OR change
  `notification-schema-rules-and-userconfig-prefs`) consumes these blocks at
  runtime once present.
- Users **opt out** per `(schema, rule)` via override-only user-config prefs;
  schema `enabled` is only the default. (`app-pending-approval` is admin-facing
  and may be made non-suppressible, matching the existing `app_pending` design.)
- Status-change rules (`secret-request-fulfilled`, `secret-compromised`) and the
  scheduled `ca-expiry` ship **disabled by default** until their preconditions
  (engine gap / scheduled date filter) are met.

## Caveats

- **HARD BLOCKER: schemas not in the register JSON.** Doriath's data model is
  entity/migration-based; `lib/Settings/doriath_register.json` has only an
  `example` schema. The mandated entities (`application`, `share`,
  `secret-request`, `secret`, `encryption-suite`) must be declared as OpenRegister
  schemas there before any rule can be attached. Prerequisite change:
  `doriath-schemas-in-register-json` (file the issue at planning time).
- **Recipient fields are assumed, not confirmed.** The rules reference
  `recipientUid`, `ownerUid`, `requesterUid`, `caNotAfter`, `compromised`,
  `status`. None of these exist on a register schema yet (the data lives on DB
  entities). When the schemas are declared, each rule's `field` recipient MUST be
  re-checked against the real property names and **dropped or remapped** if the
  field is absent. The plan explicitly flags larpingapp-style "needs a structured
  `ownerUid` first" — the same applies here for `share`/`secret-request`/`secret`.
- **Engine-gap dependence.** `secret-request-fulfilled` and `secret-compromised`
  are "field X changed to Y" rules. The `updated` trigger has **no**
  field-changed condition yet, so these depend on the OR engine change
  `notification-updated-field-change-condition` (declared in `depends_on`). Until
  it ships they cannot fire; they ship disabled.
- **`scheduled` `"now+30d"`-relative date filtering** for `ca-expiry` must be
  confirmed against the engine's filter evaluator before enabling.
- **Metadata-only subjects (security MUST).** Subjects and notification bodies
  must never carry secret values, decrypted login material, private keys, or CSR
  contents — only names/uids/dates. This is non-negotiable for a secrets manager.
- **Imperative vs declarative overlap.** While blocked, the existing imperative
  `NotificationService` dispatch (e.g. `app_pending`) remains the live path; do
  not double-dispatch. When the declarative rules go live, retire the
  corresponding imperative calls in the same change to avoid duplicate
  notifications.

See `hydra/openspec/fleet-notification-plan.md` (doriath row — "most ready: specs
mandate these (MUST); needs schemas + JSON; keep subjects metadata-only" — plus
the cross-cutting engine-gap section) for the full analysis.
