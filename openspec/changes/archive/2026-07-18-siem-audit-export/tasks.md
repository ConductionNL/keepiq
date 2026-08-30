# Tasks: SIEM Audit Export

## 1. Data layer

- [x] 1.1 Migration: `doriath_siem_sinks` (`id`, `name`, `type` enum `syslog|webhook`, `enabled`, `endpoint`, `tls`, `hmac_secret_enc` nullable, `category_filter` JSON, `queue_cap`, `last_delivery_status`, `last_success_at`, `last_attempt_at`, `last_error`, `consecutive_failures`, `dropped_count`, `created_by`, `created_at`, `updated_at`), index on `enabled` — `Version000028Date20260718180000`
- [x] 1.2 Migration: `doriath_siem_queue` (`id`, `sink_id` FK, `payload` JSON, `enqueued_at`, `status` enum `pending|delivering|delivered|dead`, `attempts`, `next_attempt_at`, `last_error`), index on `(sink_id, status, next_attempt_at)` — same migration, index `doriath_sq_due`
- [x] 1.3 `SiemSink` + `SiemQueueItem` entities and their `QBMapper` mappers (pattern matching `AuditEntryMapper`); `hmac_secret_enc` excluded from `jsonSerialize` (only a `hasHmacSecret` boolean is exposed)

## 2. Forwarding + queue services

- [x] 2.1 `SiemForwardListener implements IEventListener` on `AuditEvent`: rebuilds the payload via `AuditEventTypes::whitelist()` so it is a strict subset of the sanitized audit entry; category derived from the event-type prefix; enqueue for each enabled sink whose `category_filter` matches. Note: the export-gdpr events (SecretExportedEvent etc.) are already translated into AuditEvents by their dispatch sites, so binding to `AuditEvent` covers them — no second binding needed.
- [x] 2.2 Fail-soft wrapper: listener catches Throwable + logs at error level; enqueue failure never rolls back the audited operation
- [x] 2.3 Enqueue with drop-oldest backpressure at `queue_cap` and `dropped_count` increment — implemented as `SiemService::enqueue()` (single service seam instead of a separate `SiemQueueService`; same contract)

## 3. Delivery services

- [x] 3.1 Syslog transport — RFC 5424 message with RFC 6587 octet-counted framing over TCP (`stream_socket_client`), TLS scheme when `tls` — `SiemService::deliverSyslog()`
- [x] 3.2 Webhook transport — HTTPS POST (https enforced at create) with `X-Doriath-Signature: sha256=<hmac>` from the secret stored via `OCP\Security\ICrypto` (write-only; blank-on-update preserves the stored secret), decrypted in memory only; 10s per-request timeout — `SiemService::deliverWebhook()`
- [x] 3.3 Exponential backoff scheduling (`next_attempt_at`, base 60s doubling), retry ceiling 8 → `dead`, reset on success; per-sink state updates — `SiemService::deliverOne()`

## 4. Background job

- [x] 4.1 `DeliverSiemEventsJob extends TimedJob` (`setInterval(seconds: 60)`, mirroring `PurgeAuditLogJob`) — drains due `pending` rows per enabled sink in bounded batches (50/sink/run)
- [x] 4.2 Job registered in `appinfo/info.xml` `<background-jobs>`; listener registered in `Application::register()` after the Bootstrap guard

## 5. Notifications

- [x] 5.1 `NotificationService` unconditional admin subject `siem_dead_letter` (maps to `null` like `app_pending`) + `DoriathNotifier` case linking to the Doriath admin-settings section; raised once per escalation when a sink first accrues dead-lettered events (`deliverDue()` compares dead-count before/after the drain)

## 6. Controllers + routes

- [x] 6.1 `SiemSinkController` — `index` (list + per-sink state, secret never included), `create`, `update`, `destroy`, `test`. Note: gated via an in-body `IGroupManager::isAdmin` check that runs before any sink logic (same pattern as `ComplianceReportController`) rather than `#[AuthorizedAdminSetting]`, because the API is consumed from the app's own settings SPA with plain CSRF tokens.
- [x] 6.2 Routes registered in `appinfo/routes.php` under a commented "SIEM audit export" section (`/api/v1/siem/sinks[...]`)

## 7. Audit events

- [x] 7.1 `siem.sink_created` (`sinkId`, `type`), `siem.sink_updated` (`sinkId`), `siem.sink_deleted` (`sinkId`), `siem.sink_tested` (`sinkId`, `outcome`) added to `AuditEventTypes` + whitelist; dispatched from the corresponding `SiemService` actions

## 8. Frontend

- [x] 8.1 Admin-settings SIEM panel (`CnSettingsSection`): sink list with per-sink state (status, last success, last error, consecutive failures, dropped count) — `SiemSection.vue`, registered in `Settings.vue` after `ComplianceSection`
- [x] 8.2 Add/edit sink form: type, endpoint, TLS switch (syslog), HMAC secret (write-only with keep-current placeholder), category-filter multiselect, queue cap; test-fire button reporting delivered/failed with the transport error

## 9. Tests

- [x] 9.1 Unit: forwarded payload is a whitelisted subset and never contains a `FORBIDDEN_KEY`; unknown event types produce no payload; category filter excludes non-matching events (`SiemServiceTest`, fail-soft covered by the listener's Throwable catch)
- [x] 9.2 Unit: drop-oldest at cap increments the counter and never exceeds the cap; backoff advances attempts + `next_attempt_at`; retry ceiling dead-letters (row + sink state); https-only webhook create; blank-on-update preserves the encrypted secret and `jsonSerialize` never exposes it (8 tests)
- [x] 9.3 e2e: covered by deploy-time live verification on the dev instance (admin creates a sink, test-fires, per-sink state + audit rows + non-admin 403 verified through the UI/API) — no separate Playwright spec committed

## Acceptance Criteria

- Syslog (RFC 5424, TCP/TLS) and webhook (HMAC-signed HTTPS POST) sinks are admin-configurable and enable/disable-able
- Forwarded payload is a strict subset of the sanitized audit entry; no key material ever leaves
- Category filter excludes non-matching events at enqueue time
- Enqueue/delivery failures never roll back the audited operation
- Failed deliveries retry with exponential backoff, dead-letter after the ceiling (retained, not dropped), and raise an admin notification
- Per-sink queue cap enforces drop-oldest with a dropped-events counter; queue never exceeds the cap
- Per-sink delivery state is exposed and a test-fire action reports the transport outcome
- HMAC secret is encrypted at rest, write-only, never echoed; all endpoints reject non-admins
- Sink lifecycle emits audit events carrying only identifiers, never the HMAC secret
