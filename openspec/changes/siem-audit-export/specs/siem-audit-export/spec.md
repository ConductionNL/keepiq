---
status: proposed
---

# SIEM Audit Export

## Purpose

Stream Doriath's existing sanitized audit events to an external SIEM through admin-configured syslog (RFC 5424, TCP/TLS) and webhook (HMAC-signed HTTPS POST) sinks, with per-category filtering, reliable background delivery (exponential-backoff retry, dead-letter with admin notification, drop-oldest backpressure), per-sink observability, and a test-fire action — carrying identifiers only, never key material, per the audit trail's no-secret-material guarantee.

## ADDED Requirements

### Requirement: Admin-configured syslog and webhook sinks

Doriath SHALL let an administrator configure external SIEM sinks of type `syslog` (RFC 5424 over TCP, TLS-capable) and `webhook` (HTTPS POST), each independently enable/disable-able, and MUST restrict all sink management to administrators. A disabled sink MUST NOT receive any forwarded event.

#### Scenario: Admin creates a webhook sink

- **WHEN** an administrator creates a webhook sink with an HTTPS endpoint and an HMAC shared secret
- **THEN** the sink MUST be stored and available for delivery when enabled
- **AND** the HMAC shared secret MUST NOT be returned in any subsequent read of the sink

#### Scenario: Non-admin cannot manage sinks

- **WHEN** a regular user calls any SIEM sink endpoint
- **THEN** the request MUST be rejected by the admin authorization check before the sink logic runs

#### Scenario: Disabled sink receives nothing

- **GIVEN** a configured sink that is disabled
- **WHEN** audit events occur
- **THEN** no event MUST be forwarded to that sink

### Requirement: Forwarded payload carries no secret material

Doriath SHALL forward only the existing sanitized audit-entry shape (identifiers plus per-event-type whitelisted metadata) and MUST NOT include any secret value, login field, password, additional field, or ciphertext in any forwarded payload or in any sink's stored state.

#### Scenario: Payload is a subset of the sanitized audit entry

- **WHEN** an audit event is forwarded to a sink
- **THEN** the payload MUST contain only the audit entry's identifiers and its whitelisted metadata
- **AND** it MUST NOT contain any key, login, password, value, additional field, or ciphertext

### Requirement: Event filtering by category

Doriath SHALL let an administrator select which audit-event categories a sink forwards, MUST forward all categories when no filter is set, and MUST NOT enqueue an event whose category is excluded by the sink's filter.

#### Scenario: Filtered category is not forwarded

- **GIVEN** a sink whose filter selects only the `link_share` category
- **WHEN** a `secret.updated` event occurs
- **THEN** that event MUST NOT be enqueued for or delivered to that sink

### Requirement: Fail-soft asynchronous forwarding

Doriath SHALL forward events asynchronously via a durable per-sink queue drained by a background job, and a failure to enqueue or deliver an event MUST NOT roll back or fail the audited secret operation.

#### Scenario: Enqueue failure does not block the operation

- **GIVEN** an enabled sink
- **WHEN** enqueuing a forwarded event fails while a secret is being created
- **THEN** the secret creation MUST succeed
- **AND** the failure MUST be logged at error level

### Requirement: Retry with backoff and dead-letter notification

Doriath SHALL retry failed deliveries with exponential backoff, MUST transition an event to a dead-letter state after the retry ceiling is exceeded, MUST retain dead-lettered events rather than silently dropping them, and MUST raise an administrator notification when a sink accrues dead-lettered events.

#### Scenario: Failed delivery is retried then dead-lettered

- **GIVEN** a sink whose endpoint is unreachable
- **WHEN** the delivery job runs repeatedly past the retry ceiling for an event
- **THEN** the event MUST be moved to a dead-letter state and retained
- **AND** an administrator notification MUST be raised for that sink

### Requirement: Drop-oldest backpressure with a counter

Doriath SHALL enforce a per-sink queue cap; when the cap is reached, the oldest pending event MUST be evicted (drop-oldest) to admit a new event, and a per-sink dropped-events counter MUST be incremented, so a sink's queue never exceeds its cap.

#### Scenario: Queue at cap drops the oldest event

- **GIVEN** a sink whose pending queue is at its cap and whose endpoint is failing
- **WHEN** a new event is enqueued for that sink
- **THEN** the oldest pending event MUST be evicted and the dropped-events counter MUST increase
- **AND** the sink's pending queue MUST NOT exceed its cap

### Requirement: Per-sink delivery state and test-fire

Doriath SHALL expose per-sink delivery state (last status, last success and attempt times, last error, consecutive failures, dropped count) and SHALL provide an administrator a test-fire action that sends a synthetic event through the sink's transport and reports the outcome.

#### Scenario: Test-fire reports the outcome

- **WHEN** an administrator test-fires a configured sink
- **THEN** a synthetic event MUST be sent through the sink's transport
- **AND** the delivery outcome (success or the transport error) MUST be reported to the administrator

### Requirement: Sink lifecycle is audited

Doriath SHALL emit audit events for sink creation, update, deletion, and test-fire using the existing string-typed audit whitelist, and these events MUST NOT contain the HMAC shared secret or any secret value.

#### Scenario: Sink creation is audited without the secret

- **WHEN** an administrator creates a sink
- **THEN** a `siem.sink_created` audit event MUST be recorded carrying only the sink identifier and type
- **AND** it MUST NOT contain the HMAC shared secret or any secret value
