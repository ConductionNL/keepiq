---
kind: code
---

# Proposal: Stream audit events to external SIEM

## Why

NIS2 / Cyberbeveiligingswet (in force ~2026-08-15 for ~8,000 Dutch organisations, `docs/FEATURES.md:487`) requires logging/monitoring integration and incident-detection capability; a security team cannot detect incidents on a vault whose audit trail never leaves the box. Every enterprise competitor ships SIEM export — Keeper (Advanced Reporting & Alerts to Splunk/Sentinel), 1Password (Events API), Bitwarden (SIEM integrations) — and it is absent from every self-hosted OSS vault (Vaultwarden, Passbolt CE, Psono CE have nothing). Free-tier SIEM export is a procurement wedge consistent with Doriath's NC-inherited-enterprise positioning.

Doriath already has the exact substrate a SIEM exporter needs but never streams it anywhere — verified: no `openspec/changes/*` (active or archived) proposal covers SIEM/syslog/webhook export, and no `Siem`/`Syslog` symbol exists in `lib/` or `src/`. The audit trail dispatches typed events through a single listener (`lib/Listener/AuditListener.php:47`), `AuditService::record` (`lib/Service/AuditService.php:73`) persists a sanitized `AuditEntry` whose `jsonSerialize` (`lib/Db/AuditEntry.php:163`) already exposes exactly identifiers + a per-event-type whitelisted metadata bag, and `AuditEventTypes::whitelist()` plus `FORBIDDEN_KEYS` (`lib/Event/Audit/AuditEventTypes.php:97`) already guarantee no key material, login, value, or ciphertext can appear. A SIEM exporter is a **relay** of those already-sanitized entries — it adds no new data path into the secrets, and inherits the audit trail's no-secret-material guarantee wholesale.

The delivery machinery also already has a pattern to mirror: background jobs run on `TimedJob` (`lib/BackgroundJob/PurgeAuditLogJob.php:44`), and admin-only notifications are delivered unconditionally via `DoriathNotifier`/`NotificationService` (`lib/Service/NotificationService.php:92`, the admin `app_pending` subject) — the exact vehicle for a dead-letter alert.

## What Changes

- Add **admin-configured SIEM sinks**: `syslog` (RFC 5424 over TCP/TLS) and `webhook` (HTTPS POST, HMAC-signed with a shared secret), each enable/disable-able, with a category filter selecting which audit-event categories to forward.
- Forward the **existing typed audit events** — the already-sanitized `AuditEntry` shape (identifiers + whitelisted metadata only, per `lib/Event/Audit/AuditEventTypes.php`), never key material — via a fail-soft listener on `AuditEvent` that enqueues; an enqueue failure MUST NOT roll back the audited operation (same contract as an audit-write failure).
- Add **background delivery** via a `TimedJob` (mirroring `PurgeAuditLogJob`) that drains a per-sink queue with **exponential-backoff retry**, transitions exhausted events to a **dead-letter** state, and raises a **dead-letter admin notification** (unconditional admin subject, like `app_pending`).
- Add **backpressure handling**: a per-sink queue cap with **drop-oldest** eviction and a dropped-events counter, so a wedged sink can never grow the queue unbounded.
- Add **per-sink delivery state** (last status, last success/attempt time, last error, consecutive failures, dropped count) and a **test-fire** action that sends a synthetic event to validate a sink's config.
- Store the webhook **HMAC shared secret encrypted at rest** (`OCP\Security\ICrypto`) and never echo it back in any GET response (write-only), per the audit trail's no-secret-material discipline.
- Add **audit events** for sink lifecycle and test-fire using the existing string-typed whitelist.

## Capabilities

### New Capabilities
- `siem-audit-export`: admin-configured syslog (RFC 5424, TCP/TLS) and webhook (HMAC-signed HTTPS POST) sinks that stream the existing sanitized audit events to an external SIEM, with per-category filtering, background delivery via a `TimedJob`, exponential-backoff retry, a dead-letter state with an admin notification, drop-oldest backpressure with a counter, per-sink delivery state, and a test-fire action — carrying identifiers only, never key material.

### Modified Capabilities
- _(none — this change consumes `secret-audit-trail`'s events and sanitization by reference and adds no MODIFIED requirement to its scenarios.)_

## Impact

- **New tables** (own DB per ADR-001): `doriath_siem_sinks`, `doriath_siem_queue`. **No** OpenRegister.
- **Services**: new `SiemSinkService` (CRUD + test-fire), `SiemQueueService` (enqueue/backpressure), `SiemDeliveryService` (syslog + webhook transports, HMAC signing, backoff). New `SiemForwardListener implements IEventListener` on `AuditEvent` (fail-soft enqueue).
- **Background job**: new `DeliverSiemEventsJob` registered in `appinfo/info.xml` `<background-jobs>` alongside the CA/audit/emergency jobs.
- **Routes/controllers**: new `SiemSinkController`, all `#[AuthorizedAdminSetting(AdminSettings::class)]` (admin-only, mirroring `AuditController::index`).
- **Notifications**: new `DoriathNotifier` case + `NotificationService` unconditional admin subject `siem_dead_letter`.
- **Frontend**: an admin-settings SIEM panel (add/edit/enable sinks, category filter, per-sink state, test-fire button), reusing `CnSettingsSection`.
- **Audit**: new event types added to `AuditEventTypes` (no DB migration — string types).
- **OpenConnector**: none — the machine API is untouched.
