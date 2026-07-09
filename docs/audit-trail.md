<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Audit trail

Doriath records an **append-only audit trail** of secret operations for
municipal accountability: knowing *who did what, when* to a credential. The
trail starts at the deployment of this feature — there is no historical
backfill.

## What is observable (and what is not)

Doriath is always end-to-end encrypted (ADR-003): the server observes API
operations on encrypted blobs but **never** sees plaintext. "Audit trail on all
secret operations" therefore honestly means:

- **Server-observable operations** — recorded completely. Secret create /
  update / read (individual encrypted-blob fetch) / delete; folder cascade
  deletion; share grant / revoke / delegate / reclaim; link-share create /
  access / failed-access / revoke / auto-delete; secret-request create /
  fulfil / re-request / revoke; suite revoke / reinstate / recovery; application
  register / approve / reject / delete / token-issue / secret-retrieve.
- **Client-reported flows** — export / GDPR-export / account-deletion are
  consumed from the typed events the secret-export capability emits. Because
  export runs in the browser, these are **honest-client** reports, not
  server-enforced observations. This limitation is inherited verbatim from the
  secret-export capability and is stated rather than implied away.
- **Not recorded** — in-browser actions (clipboard copy, reveal-password
  toggle, in-browser decrypt) are not server-observable under E2E. No IP
  address or user-agent is captured (privacy by default in v1).

`list` and `search` are **not** per-secret reads and do not produce
`secret.read` entries — only an individual blob fetch does.

## No secret material, ever

Audit entries carry metadata only. Each entry is validated against a
per-event-type **whitelist** of keys; unknown keys are dropped, and the keys
`key`, `login`, `password`, `value`, `additionalFields`, `ciphertext`,
`payload` are rejected outright in any position. A secret's `name` (plaintext
metadata, safe to display, denormalized so the entry survives deletion) is the
only object-identifying string stored.

## Append-only

The log is append-only at the application surface: no API endpoint or service
method edits or deletes an individual entry. The only two permitted mutations
are the retention purge and account-deletion anonymization, both internal to
`AuditService`. (Append-only is a policy, not cryptography — hash-chain
tamper-evidence is a deferred Enterprise-tier candidate.)

## Retention

Retention is an admin setting (`audit_retention_days`, default **365**, hard
minimum **30** — below that the trail cannot serve incident investigation). A
nightly background job (`PurgeAuditLogJob`) deletes entries older than the
window in bounded batches.

## Account-deletion anonymization

When a user's account data is deleted, the deleted user is **anonymized out**
of the trail rather than deleted: their actor id and user-referencing metadata
become a `deleted-account` marker. Entries are retained so *other* users'
accountability records (a share they received, a delegation they hold) survive.
No personal data of the deleted user remains.

## Views

- **Per-secret activity** — an Activity section on the secret detail, available
  to the secret's owner. A non-owner gets the same response as a nonexistent
  secret (no existence oracle).
- **Personal activity** — the session user's own operations (My activity).
- **Admin audit view** — instance-wide, paginated (50/page), filterable by
  event type / actor / object / date range, with a client-side CSV export of
  the current filter result (no server download endpoint).

**Admin visibility note:** the admin view shows all entries, including which
user read which secret *name*. This is acceptable for an accountability tool
(names are plaintext metadata in the database anyway) and is documented here so
operators and works councils know it is the default.

## Event-type reference

`secret.created`, `secret.updated`, `secret.read`, `secret.deleted`,
`folder.deleted_cascade`, `share.granted`, `share.revoked`, `share.delegated`,
`share.delegation_reclaimed`, `link_share.created`, `link_share.accessed`,
`link_share.access_failed`, `link_share.revoked`, `link_share.auto_deleted`,
`request.created`, `request.fulfilled`, `request.re_requested`,
`request.revoked`, `suite.revoked`, `suite.reinstated`,
`suite.recovery_started`, `suite.recovery_completed`, `application.registered`,
`application.approved`, `application.rejected`, `application.deleted`,
`application.token_issued`, `application.secret_retrieved`, `vault.exported`,
`vault.gdpr_exported`, `vault.account_deleted`.
