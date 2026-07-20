# Design: Honey Credentials

## Context

Zero-knowledge (ADR-003) forbids the server from inspecting secret *content*, but every server-observable *access* to a secret already flows through one seam: `SecretService::dispatchAudit` (`:166`) and the sibling services dispatch typed `AuditEvent`s on the `OCP\EventDispatcher` bus:

- `secret.read` — individual encrypted-blob fetch via `SecretService::get` (`:705`) — the UI reveal and any CLI/extension fetch both land here.
- `application.secret_retrieved` — machine-API fetch (`SecretService::getByNameForApplication` `:667`, and the HTTP envelope full-read).
- `link_share.accessed` — anonymous link visitor (`LinkShareService` `:246`, `AuditEvent::forLinkVisitor`).
- share-recipient reads of a shared secret's blob.

`AuditEvent` carries actor identity + `objectType`/`objectId` (`lib/Event/Audit/AuditEvent.php` — `forUser`/`forApplication`/`forSystem`/`forLinkVisitor`). `AuditService::record` (`:56`) is fail-soft: an audit-write failure never rolls back the audited operation. Deception rides this exact stream.

## Goals / Non-Goals

**Goals:**
- Mark a decoy secret and page the owner + admins the instant anyone accesses it, across UI / machine / link / share channels.
- One central wiring point, not per-controller edits — cover every channel that emits a per-object read event.
- Invisible to recipients/attackers — the decoy is indistinguishable from a real secret.
- Never block, slow, or fail the observed access (fail-soft).
- Rate-limit alert storms; per-accessor acknowledge/snooze for false positives.

**Non-Goals:**
- Detecting content misuse (impossible under zero-knowledge — we only see access).
- Auto-response / blocking the accessor (this is detection, not prevention).
- Generating decoy values automatically — the owner authors the decoy secret like any other.

## Data Model

Own tables per **ADR-001**.

### `doriath_honey_flags`
The flag lives here, **never** on the `doriath_secrets` row and never in its `jsonSerialize`, so a recipient/attacker cannot distinguish a honey secret.

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | PK |
| `secret_id` | string FK, unique | The flagged decoy secret |
| `owner_id` | string | Secret owner (NC user id) |
| `note` | text nullable | Placement note (owner/admin only) |
| `created_by` | string | Who flagged it (owner or admin) |
| `created_at` | datetime | |

### `doriath_honey_alerts`
One row per raised alert (after dedup).

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | PK |
| `honey_flag_id` | string FK | |
| `secret_id` | string | Denormalized for query/survival |
| `accessor_type` | enum | `user` \| `application` \| `link_visitor` \| `system` |
| `accessor_id` | string nullable | Null for anonymous link visitors |
| `channel` | enum | `ui` \| `machine_api` \| `link` \| `share` |
| `ip` | string nullable | From `IRequest` when available |
| `user_agent` | string nullable | From `IRequest` when available |
| `accessed_at` | datetime | |
| `acknowledged_at` | datetime nullable | |
| `acknowledged_by` | string nullable | |
| `snoozed_until` | datetime nullable | Per-accessor suppression watermark |

## Decisions

### D1: Central listener on the typed audit stream (one seam, all channels)
`HoneyTripwireListener` subscribes to `AuditEvent`. On each event it checks `objectType === 'secret'` and whether `objectId` is honey-flagged (indexed lookup). If so it raises an alert. This covers UI/machine/link/share with a single registration instead of touching every controller. **Honest boundary:** a channel that emits no per-object read event cannot trip the wire — stated in the spec, not implied away.

### D2: Channel derived from source event type
`secret.read → ui`, `application.secret_retrieved → machine_api`, `link_share.accessed → link`, share-recipient read → `share`. UI reveal and CLI/extension both hit `get()` and are therefore indistinguishable — both report `ui`. Accepted; the accessor identity + IP/user-agent disambiguate in practice.

### D3: Alerts are NOT gated by the notification opt-out
Every other subject in `NotificationService::SUBJECT_SETTING_MAP` (`:50`) maps to a `notify_*` preference; a muted tripwire is worthless, so `honey_access` maps to `null` (ungated, like `app_pending`). A honey alert always reaches the owner and all admins regardless of their security-notification preference.

### D4: Fail-soft, exactly like the audit listener
The tripwire runs after the access is already served; an alert-write or notification failure is logged and swallowed — it never rolls back or delays the read. Same posture as `AuditService::record`.

### D5: Rate-limited alert storms + per-accessor snooze
Dedup key = `(honey_flag_id, accessor_type, accessor_id, channel)`; within a configurable window (default 1h) repeated accesses update the existing alert's `accessed_at` count rather than paging again. An owner/admin `snooze` sets `snoozed_until` for that accessor, suppressing pages (but still recording the distinguished `honey.accessed` audit event, so the forensic trail stays complete). `acknowledge` marks the alert handled.

### D6: Distinguished audit event, no secret material
A honey access records a `honey.accessed` audit event **in addition** to whatever read event fired, so an admin filtering the audit view sees the high-severity marker distinctly. Its whitelist permits only `channel` (accessor identity is the actor field); the forbidden-key guard structurally blocks any secret material.

### D7: Flag is owner- or admin-settable; alerts owner+admin-visible
Flagging requires ownership or admin. Listing alerts is owner-scoped (own decoys) or, for admins, instance-wide — mirroring the audit trail's per-secret vs admin split. Recipients never see the flag or the alerts.

## Endpoints

`HoneyController`, explicit auth attributes, per-object guards (no IDOR).

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| POST | `/api/v1/secrets/{id}/honey` | `#[NoAdminRequired]` | Flag a secret honey (owner or admin) |
| DELETE | `/api/v1/secrets/{id}/honey` | `#[NoAdminRequired]` | Unflag |
| GET | `/api/v1/honey/alerts` | `#[NoAdminRequired]` | Owner: own alerts; admin: instance-wide |
| POST | `/api/v1/honey/alerts/{id}/acknowledge` | `#[NoAdminRequired]` | Mark handled |
| POST | `/api/v1/honey/alerts/{id}/snooze` | `#[NoAdminRequired]` | Snooze future alerts for that accessor |

## Alert flow

1. Access served normally (read/retrieve/link/share) → typed `AuditEvent` dispatched.
2. `HoneyTripwireListener` fires (fail-soft): `objectType=secret` + `objectId` honey-flagged?
3. If yes: dedup by accessor+window (D5). New/unsuppressed → insert `doriath_honey_alerts` row (accessor, channel, IP/UA from `IRequest`).
4. Dispatch `honey_access` notification to owner + all admins (ungated, D3).
5. Dispatch `honey.accessed` distinguished audit event (D6).
6. If `siem-audit-export` class present, emit its SIEM event (class-existence-guarded).

## Declarative-vs-imperative decision

Imperative PHP services, listener, and migrations per **ADR-001** — no OpenRegister / declarative object model.

## Decisions made under uncertainty

- **Central listener over per-controller wiring (D1).** We assume the existing typed audit stream reaches every server-observable access; a channel emitting no per-object read event is an explicit honest gap, not a silent one.
- **Alerts bypass the notification opt-out (D3).** We assume a security tripwire must always page; if an operator disagrees, an admin `honey_access_enabled` kill switch is the escape hatch (deferred).
- **UI reveal and CLI/extension are indistinguishable (D2)** — both hit `get()`; reported as `ui`, disambiguated by accessor/IP.
- **Snooze suppresses pages but not the forensic record (D5).** We assume operators want the audit trail complete even while a known-benign accessor is muted.
- **The decoy is a normal encrypted secret with the flag in a side table** — we assume never serializing the flag is sufficient to keep it invisible; the secret's own response shape is unchanged.
- **SIEM is optional/deferred** — guarded on a sibling change that is not yet built, exactly like the audit trail's not-yet-built export events.
