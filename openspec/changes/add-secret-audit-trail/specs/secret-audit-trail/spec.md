## ADDED Requirements

### Requirement: Server-Observable Operation Capture
The system MUST record an audit entry for every server-observable secret operation: secret created, updated, read (individual encrypted-blob fetch), and deleted; folder cascade deletion; share granted, revoked, delegated, and delegation reclaimed; link share created, accessed, access-attempt failed, revoked, and auto-deleted; secret request created, fulfilled, re-requested, and revoked; suite revoked, reinstated, and compromise recovery started/completed; application registered, approved, rejected, deleted, token issued, and application secret retrieved.

Each entry MUST record the timestamp, actor (user, application, system, or anonymous link visitor), event type, object reference, and the object's non-sensitive name (denormalized so the entry survives object deletion). All entries MUST be written through typed events dispatched via `OCP\EventDispatcher` and a single audit listener; an audit-write failure MUST NOT fail or roll back the audited operation.

List and search calls are not per-secret reads and MUST NOT produce `secret.read` entries.

#### Scenario: Secret update is logged
- **WHEN** a user updates a secret they own
- **THEN** an audit entry MUST be recorded with event type `secret.updated`, the user as actor, and the secret's id and name

#### Scenario: Failed link-share access attempt is logged
- **WHEN** an anonymous visitor enters a wrong password on a link share
- **THEN** an audit entry MUST be recorded with event type `link_share.access_failed`, actor type `link_visitor`, and no actor id
- **AND** the entry MUST NOT contain the attempted password

#### Scenario: Entry survives object deletion
- **WHEN** a secret is deleted
- **THEN** the `secret.deleted` entry and all prior entries for that secret MUST remain readable, displaying the secret's recorded name

#### Scenario: Audit failure does not block the operation
- **WHEN** the audit listener fails while a secret is being created
- **THEN** the secret creation MUST succeed
- **AND** the failure MUST be logged at error level

### Requirement: Export and Deletion Event Consumption
The system MUST register listeners for the three typed events scoped by the secret-export capability — `SecretExportedEvent`, `GdprExportPerformedEvent`, and `AccountDataDeletedEvent` — and persist them as audit entries (`vault.exported`, `vault.gdpr_exported`, `vault.account_deleted`) carrying the events' existing payloads (mode, scope, counts, trigger) unchanged. No new dispatch sites are added for these operations.

Because export runs client-side, these entries cover the supported, client-reported flows only; this honest-client limitation is inherited from the secret-export capability and MUST be stated in the audit documentation rather than implied away.

#### Scenario: Export event becomes an audit entry
- **WHEN** a `SecretExportedEvent` is dispatched for a user who exported 120 secrets as an encrypted backup
- **THEN** an audit entry MUST be recorded with event type `vault.exported` and metadata `mode: encrypted-backup`, `secretCount: 120`

#### Scenario: Account deletion becomes an audit entry
- **WHEN** an `AccountDataDeletedEvent` is dispatched with trigger `user-deleted`
- **THEN** an audit entry MUST be recorded with event type `vault.account_deleted` carrying the per-entity deletion counts

### Requirement: No Secret Material in Audit Entries
Audit entries MUST NEVER contain secret values, login fields, additional fields, plaintext passwords, or ciphertext. Entry metadata MUST be validated against a per-event-type whitelist of keys; unknown keys MUST be dropped, and any attempt to record a forbidden key (`key`, `login`, `password`, `value`, `additionalFields`, `ciphertext`, `payload`) MUST be rejected with an error. A secret's `name` and `url` (plaintext metadata per the secrets spec) are the only object-identifying strings permitted.

#### Scenario: Forbidden metadata rejected
- **WHEN** a caller attempts to record an audit entry whose metadata contains a `password` key
- **THEN** the audit service MUST reject the entry with an exception

#### Scenario: Database dump reveals no secret material
- **WHEN** the full `doriath_audit_log` table is inspected directly
- **THEN** no row contains a secret value, login field, additional field, or ciphertext in any column

### Requirement: Append-Only Log
The audit log MUST be append-only at the application surface: no API endpoint and no service method may edit or delete individual entries. The only permitted mutations are the retention purge and account-deletion anonymization, both internal to the audit service.

#### Scenario: No mutation surface exists
- **WHEN** the registered routes and audit service public API are enumerated
- **THEN** no operation exists that updates or deletes an individual audit entry

### Requirement: Retention and Automated Purge
The retention window MUST be configurable by an administrator (`audit_retention_days`, default 365), with a hard minimum of 30 days that cannot be configured lower. A nightly background job MUST delete entries older than the window in bounded batches.

#### Scenario: Expired entries purged
- **WHEN** the purge job runs with a 365-day retention window
- **THEN** all entries older than 365 days MUST be deleted
- **AND** entries within the window MUST be untouched

#### Scenario: Retention floor enforced
- **WHEN** an administrator attempts to set retention to 7 days
- **THEN** the system MUST reject the value with an error explaining the 30-day minimum

### Requirement: Account-Deletion Anonymization
When a user's account data is deleted (`AccountDataDeletedEvent`), the system MUST anonymize the deleted user out of existing audit entries: entries with the user as actor have the actor id replaced by a `deleted-account` marker, and whitelisted metadata fields referencing the user are replaced by the same marker. The entries themselves MUST be retained — other users' accountability records reference these operations. No user id, display name, or other personal data of the deleted user may remain in the log afterwards.

#### Scenario: Actor anonymized on account deletion
- **WHEN** a user's account data is deleted
- **THEN** all audit entries previously listing that user as actor MUST show the `deleted-account` marker instead of the user id
- **AND** entries recording shares to that user MUST no longer contain the user id in metadata

#### Scenario: Other users' history preserved
- **WHEN** a user who had shared a secret to a colleague is deleted
- **THEN** the colleague's `share.granted` entry MUST still exist (anonymized), preserving when and how they received access

### Requirement: Per-Secret and Personal Activity Views
The system MUST provide a per-secret activity view (entries for one secret, newest first) available to the secret's current owner, and a personal activity view listing the session user's own operations. A share recipient MUST see only entries they actored on the shared secret, not the owner's full history. Requesting the activity of a secret the user has no access to MUST return the same response as a nonexistent secret (no existence oracle).

#### Scenario: Owner views secret activity
- **WHEN** an owner opens the Activity tab of their secret
- **THEN** the entries for that secret MUST be listed newest first with actor, event type, and timestamp

#### Scenario: Non-owner blocked without existence oracle
- **WHEN** a user requests the activity of a secret they do not own
- **THEN** the response MUST be indistinguishable from requesting a nonexistent secret

### Requirement: Admin Audit View
The system MUST provide administrators an instance-wide audit view: paginated (50 per page), filterable by event type, actor, object type/id, and date range, and exportable as CSV generated client-side from the fetched rows. Non-administrators MUST NOT be able to reach the admin audit endpoints. The view MUST state that the trail starts at the deployment of this capability (no historical backfill).

#### Scenario: Admin filters by event type and actor
- **WHEN** an administrator filters the audit view to `link_share.access_failed` within a date range
- **THEN** only matching entries MUST be returned, paginated with a total count

#### Scenario: Non-admin rejected
- **WHEN** a regular user calls the instance-wide audit endpoint
- **THEN** the request MUST be rejected by the admin authorization check
