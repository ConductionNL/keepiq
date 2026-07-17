# Tasks: SIEM Audit Export

## 1. Data layer

- [ ] 1.1 Migration: `doriath_siem_sinks` (`id`, `name`, `type` enum `syslog|webhook`, `enabled`, `endpoint`, `tls`, `hmac_secret_enc` nullable, `category_filter` JSON, `queue_cap`, `last_delivery_status`, `last_success_at`, `last_attempt_at`, `last_error`, `consecutive_failures`, `dropped_count`, `created_by`, `created_at`, `updated_at`), index on `enabled`
- [ ] 1.2 Migration: `doriath_siem_queue` (`id`, `sink_id` FK, `payload` JSON, `enqueued_at`, `status` enum `pending|delivering|delivered|dead`, `attempts`, `next_attempt_at`, `last_error`), index on `(sink_id, status, next_attempt_at)`
- [ ] 1.3 `SiemSink` + `SiemQueueItem` entities and their `QBMapper` mappers (pattern matching `AuditEntryMapper`); `hmac_secret_enc` excluded from `jsonSerialize`

## 2. Forwarding + queue services

- [ ] 2.1 `SiemForwardListener implements IEventListener` on `AuditEvent` (+ export-gdpr events): rebuild the payload via `AuditEventTypes::whitelist()` so it is a strict subset of the sanitized audit entry; category derived from the event-type prefix (`secret`/`share`/`link_share`/`request`/`suite`/`application`/`emergency_access`/`vault`); enqueue for each enabled sink whose `category_filter` matches
- [ ] 2.2 Fail-soft wrapper: catch + log at error level; enqueue failure MUST NOT roll back the audited operation
- [ ] 2.3 `SiemQueueService::enqueue()` with drop-oldest backpressure at `queue_cap` and `dropped_count` increment

## 3. Delivery services

- [ ] 3.1 `SiemDeliveryService` syslog transport — RFC 5424 framing over TCP, TLS when `tls`
- [ ] 3.2 `SiemDeliveryService` webhook transport — HTTPS POST with `X-Doriath-Signature` HMAC-SHA256 from the secret stored/read via `OCP\Security\ICrypto` (write-only; blank-on-update preserves the stored secret), decrypted in memory only; per-request timeout
- [ ] 3.3 Exponential backoff scheduling (`next_attempt_at`), retry ceiling → `dead`, reset on success; per-sink state updates

## 4. Background job

- [ ] 4.1 `DeliverSiemEventsJob extends TimedJob` (`setInterval(60)`, mirroring `PurgeAuditLogJob`) — drain due `pending` rows per enabled sink in bounded batches
- [ ] 4.2 Register the job in `appinfo/info.xml` `<background-jobs>` and the listener in `Application::register()`

## 5. Notifications

- [ ] 5.1 `NotificationService` unconditional admin subject `siem_dead_letter` + `DoriathNotifier` case; raise once per escalation when a sink first accrues dead-lettered events

## 6. Controllers + routes

- [ ] 6.1 `SiemSinkController` — `index` (list + per-sink state, secret never included), `create`, `update`, `destroy`, `test` (test-fire); all `#[AuthorizedAdminSetting(AdminSettings::class)]`
- [ ] 6.2 Register routes in `appinfo/routes.php` under a commented "SIEM audit export" section

## 7. Audit events

- [ ] 7.1 Add `siem.sink_created` (`sinkId`, `type`), `siem.sink_updated` (`sinkId`), `siem.sink_deleted` (`sinkId`), `siem.sink_tested` (`sinkId`, `outcome`) to `AuditEventTypes`; dispatch on the corresponding actions

## 8. Frontend

- [ ] 8.1 Admin-settings SIEM panel (`CnSettingsSection`): list sinks with per-sink state (status, last success/error, consecutive failures, dropped count)
- [ ] 8.2 Add/edit sink form: type, endpoint, TLS, HMAC secret (write-only), category-filter multiselect, queue cap; test-fire button reporting the outcome

## 9. Tests

- [ ] 9.1 Unit: forwarded payload is a subset of the sanitized audit entry and never contains a `FORBIDDEN_KEY`; category filter excludes non-matching events; enqueue failure does not roll back the audited operation (fail-soft)
- [ ] 9.2 Unit: drop-oldest at cap increments the counter and never exceeds the cap; backoff advances; retry ceiling dead-letters and raises the admin notification; HMAC secret is encrypted at rest, never in a GET, blank-on-update preserves it; every endpoint rejects non-admins
- [ ] 9.3 e2e (Playwright): admin creates a webhook sink, test-fires it, sees per-sink state; a failing sink surfaces dropped-count and a dead-letter notification

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
