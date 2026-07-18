# Design: Stream audit events to external SIEM

## Context

Doriath's audit trail already produces exactly what a SIEM needs and nothing more: typed events dispatched through a single listener (`lib/Listener/AuditListener.php:47`), persisted by `AuditService::record` (`lib/Service/AuditService.php:73`) as an `AuditEntry` whose serialized shape (`lib/Db/AuditEntry.php:163`) is `occurredAt, actorType, actorId, eventType, objectType, objectId, objectName` plus a per-event-type **whitelisted** metadata bag. `AuditEventTypes::whitelist()` and `FORBIDDEN_KEYS` (`lib/Event/Audit/AuditEventTypes.php:97`) already guarantee no `key`, `login`, `password`, `value`, `additionalFields`, or `ciphertext` can appear. A SIEM exporter is therefore a **relay** of these already-sanitized entries: it introduces no new path into the secrets and inherits the audit trail's no-secret-material guarantee unchanged.

What is missing is the transport and the delivery lifecycle: admin-configured sinks, a durable queue, retry/backoff, dead-lettering, backpressure, and per-sink state — none of which exist today (verified: no `Siem`/`Syslog` symbol in `lib/` or `src/`).

## Goals / Non-Goals

**Goals:**
- Admin-configured `syslog` (RFC 5424, TCP/TLS) and `webhook` (HMAC-signed HTTPS POST) sinks, enable/disable-able, with a per-category event filter.
- Forward the already-sanitized `AuditEntry` shape only — identifiers + whitelisted metadata, never key material.
- Reliable background delivery: exponential-backoff retry, a dead-letter state with an admin notification, and drop-oldest backpressure with a counter.
- Per-sink observability (last status/time/error, consecutive failures, dropped count) and a test-fire action.

**Non-Goals:**
- Changing what the audit trail records or the whitelist — this change only relays existing entries.
- Vendor-specific formatting beyond RFC 5424 syslog and a documented JSON webhook envelope (Splunk HEC / Sentinel adapters are a future add).
- Pulling historical entries into a newly added sink — a sink streams from its creation forward (stated to admins), matching the audit trail's own no-backfill posture.
- Inbound SIEM control / bidirectional integration.

## Declarative-vs-imperative decision

Imperative, per **ADR-001** (`openspec/architecture/adr-001-own-database-tables.md`): Doriath owns all its tables and does not use OpenRegister. Sinks and the delivery queue are new own Doctrine entities with `ISchemaWrapper` migrations; there is no register/schema seed-data step and no declarative object model.

## Data model (own tables per ADR-001)

**`doriath_siem_sinks`** (index on `enabled`):

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID | Primary key |
| `name` | string | Admin-facing label |
| `type` | enum `syslog` \| `webhook` | Transport |
| `enabled` | bool | Disabled sinks are skipped by the forwarder and the job |
| `endpoint` | string | syslog `host:port`; webhook HTTPS URL |
| `tls` | bool | syslog: TCP/TLS vs plain TCP (default true) |
| `hmac_secret_enc` | text, nullable | webhook HMAC shared secret, **encrypted at rest** via `OCP\Security\ICrypto`; write-only, never returned in a GET |
| `category_filter` | JSON | Selected event categories; empty = all categories |
| `queue_cap` | int | Max pending events for this sink (default 10000) |
| `last_delivery_status` | enum `ok` \| `failing` \| `dead_letter` \| `never` | Per-sink health |
| `last_success_at` / `last_attempt_at` | datetime, nullable | Observability |
| `last_error` | string, nullable | Truncated last transport error (no payload) |
| `consecutive_failures` | int | Drives backoff + dead-letter escalation |
| `dropped_count` | int | Cumulative drop-oldest evictions (backpressure counter) |
| `created_by` / `created_at` / `updated_at` | — | Audit |

**`doriath_siem_queue`** (index on `(sink_id, status, next_attempt_at)`):

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID | Primary key |
| `sink_id` | FK (`doriath_siem_sinks`) | Owning sink |
| `payload` | JSON | The **sanitized** `AuditEntry` shape (identifiers + whitelisted metadata only) — never key material |
| `enqueued_at` | datetime | Ordering for drop-oldest |
| `status` | enum `pending` \| `delivering` \| `delivered` \| `dead` | Lifecycle |
| `attempts` | int | Retry counter |
| `next_attempt_at` | datetime | Exponential-backoff schedule |
| `last_error` | string, nullable | Truncated transport error, no payload echo |

## Event categories

Categories are derived from the event-type prefix already grouped in `AuditEventTypes` (`secret`, `folder`, `share`, `link_share`, `request`, `suite`, `application`, `emergency_access`, `vault`). A sink's `category_filter` selects prefixes; an empty filter forwards all. Category selection happens at enqueue time so a filtered-out event never enters the queue.

## Forwarding path (fail-soft)

`SiemForwardListener implements IEventListener<Event>` subscribes to the same `AuditEvent` the audit listener consumes (and the export-gdpr events). For each event it builds the payload by re-applying `AuditEventTypes::whitelist()` — so the SIEM payload can never carry more than a persisted audit entry — and calls `SiemQueueService::enqueue()` for every enabled sink whose category filter matches. Enqueue is wrapped so a failure is caught and logged at error level and **MUST NOT** roll back or fail the audited operation (the same fail-soft contract as the audit-write path, `secret-audit-trail` "Audit failure does not block the operation").

`enqueue()` enforces backpressure: if the sink's pending count is at `queue_cap`, the oldest `pending` row is evicted and `dropped_count` incremented before the new row is inserted (drop-oldest).

## Endpoints (`appinfo/routes.php`, all admin-only)

All methods carry `#[AuthorizedAdminSetting(AdminSettings::class)]`, mirroring `AuditController::index` (`lib/Controller/AuditController.php:183`):

- `GET /api/v1/siem/sinks` — list sinks with per-sink state; the HMAC secret is **never** included.
- `POST /api/v1/siem/sinks` — create a sink (encrypts the HMAC secret at rest). `PUT /api/v1/siem/sinks/{id}` — update; a blank secret leaves the stored one unchanged. `DELETE /api/v1/siem/sinks/{id}` — remove sink + its queued rows.
- `POST /api/v1/siem/sinks/{id}/test` — test-fire a synthetic event through the transport and report the outcome, without enqueuing (immediate result).

## Delivery job (mirrors existing `TimedJob` patterns)

`DeliverSiemEventsJob extends TimedJob`, registered in `appinfo/info.xml` `<background-jobs>` next to `PurgeAuditLogJob`/`ApproveElapsedEmergencyRequests`, short interval (`setInterval(60)`) so streaming is near-real-time. Each run, per enabled sink, drains `pending` rows whose `next_attempt_at` has passed in bounded batches:
- **syslog** transport frames the payload as RFC 5424 over TCP (TLS when `tls`);
- **webhook** transport POSTs the JSON envelope over HTTPS with an `X-Doriath-Signature` HMAC-SHA256 header computed from `hmac_secret_enc` (decrypted in memory only);
- success → `delivered`, reset `consecutive_failures`, set `last_success_at`, `last_delivery_status = ok`;
- failure → increment `attempts`, set `next_attempt_at = now + backoff(attempts)` (exponential, capped), `last_delivery_status = failing`; once `attempts` exceeds the retry ceiling → `status = dead`, and when a sink first accrues dead rows, dispatch the `siem_dead_letter` admin notification once per escalation.

## Audit events

Add to `lib/Event/Audit/AuditEventTypes.php` (string types, migration-free): `siem.sink_created` (whitelist `sinkId`, `type`), `siem.sink_updated` (`sinkId`), `siem.sink_deleted` (`sinkId`), `siem.sink_tested` (`sinkId`, `outcome`). Each inherits the `FORBIDDEN_KEYS` guard; the HMAC secret is never an event field.

## Risks / Trade-offs

- **A wedged sink grows the queue unbounded** → per-sink `queue_cap` with drop-oldest eviction and a `dropped_count` counter surfaced in the panel; the queue can never exceed the cap.
- **Secret material leaking to a third-party SIEM** → the payload is built by re-applying the existing whitelist; a reviewer/test asserts the SIEM payload is a strict subset of the sanitized `AuditEntry` and never contains a `FORBIDDEN_KEY`. No new data path into the secrets is introduced.
- **HMAC secret at rest** → stored via `OCP\Security\ICrypto`, write-only, never returned in any GET; a blank secret on update preserves the stored one (so the panel never needs to re-enter it).
- **Delivery failure silently losing events** → dead-lettering preserves failed rows in a `dead` state (not deleted) and raises an admin notification, so an operator is alerted rather than losing visibility.
- **A slow transport blocking the job** → per-request timeouts and bounded batches per run; a sink that times out simply backs off and retries next run.

## Migration Plan

1. Add `doriath_siem_sinks` + `doriath_siem_queue` via an `ISchemaWrapper` migration (additive).
2. Ship the services, listener, controller, routes, and delivery job; register the job in `info.xml` and the listener in `Application::register()`.
3. Register the new audit event types and the `siem_dead_letter` notification subject.
4. No backfill — sinks stream from creation forward (stated to admins).
5. Rollback: disable/remove sinks (delivery stops immediately), unregister the job/listener; the additive tables are inert. No existing data is mutated.

## Decisions made under uncertainty

- **The SIEM payload is the already-sanitized audit entry, re-whitelisted at enqueue.** Rather than trusting the event object, the forwarder rebuilds the payload through `AuditEventTypes::whitelist()`, so a SIEM export can never carry more than a persisted audit entry — the no-secret-material guarantee is inherited, not re-implemented. Alternative (forward the raw event payload) rejected as a second, weaker sanitization surface.
- **Forwarding is fail-soft and asynchronous.** Enqueue failures are caught and never roll back the audited operation (same contract as the audit-write path); a durable queue drained by a `TimedJob` means an unreachable SIEM slows delivery but never blocks a user's secret operation. Cost: near-real-time, not synchronous, delivery.
- **Backpressure is drop-oldest with a counter, not block-newest.** A wedged sink must never stall the audit path or grow storage without bound; dropping the oldest pending events (and counting drops for the admin) preserves the freshest security signal and bounds the queue. Cost: under sustained sink failure, the oldest events are lost — surfaced explicitly via `dropped_count` and the dead-letter alert.
- **Dead-lettered events are retained, not deleted, and raise an admin alert.** Silent loss is the worst outcome for a compliance log; keeping `dead` rows and notifying the admin (unconditional admin subject like `app_pending`) turns a delivery failure into an actionable signal. Cost: `dead` rows accrue until an admin acts or the retention purge reaps them.
- **The HMAC secret is encrypted at rest and never echoed.** It is infra config, not a user vault secret, but it is still a credential; storing it via `ICrypto` and making it write-only (blank-preserves-on-update) keeps it out of every GET response and DB dump in plaintext, consistent with the app's no-secret-material discipline.
- **Sinks stream forward-only, no historical backfill.** Matches the audit trail's own no-backfill stance (`secret-audit-trail` Admin Audit View), keeps the delivery job bounded, and avoids a newly added sink replaying the entire log. Stated to admins on sink creation.
