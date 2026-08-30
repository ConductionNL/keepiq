<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Secret request expiry

A secret request hands someone a link so they can put a credential **into** your
vault without being able to read anything in it. That link is a bearer
credential: whoever holds the URL can submit against it. Expiry is how a request
stops being a live way in once you no longer need it.

## What the requester sets

The expiry is **optional**, and the create dialog pre-fills a suggestion of
**14 days** that you can change or clear.

Clearing it is a deliberate choice, not an oversight: a request with no expiry
stays open until it is filled or you revoke it by hand. That is still supported —
a perpetual link is one action away — it just stops being what you get by
accident from leaving an empty field alone.

Requests created through the API are unaffected: `expiresAt` is optional there
and stays null when omitted.

## What happens when an expiry passes

Two independent mechanisms, and the distinction matters:

**The access check is the enforcement.** Every time a fill link is opened, the
server evaluates `expires_at` itself, against the clock, regardless of what
status the request is stored with. A request that lapsed one minute ago is
refused on that basis alone. Nothing about this waits for a job to run.

**The background job is cleanup.** `ExpireSecretRequestsJob` runs hourly and
sweeps requests whose expiry has passed:

| It does | It never does |
|---|---|
| Moves the request to the terminal status `expired` | Touches a request with **no** `expires_at` |
| Deletes the empty placeholder Secret a fresh request created | Deletes a Secret that holds a value |
| Records `request.expired` in the audit trail, attributed to the **system** | Records it as though you revoked it |
| Keeps the request row, so the vault change is explainable | Removes the evidence of what happened |

A re-request targets a Secret that already holds a credential. If such a request
lapses, the request ends but **the Secret and its current values are preserved** —
a request running out of time must never cost you a working credential.

## Expired is not cancelled

`expired` and `declined` are separate terminal states on purpose. If a row
disappears from a vault, you should be able to tell "I cancelled this" from
"this ran out of time", months later, from the audit trail. An automatic expiry
is attributed to the system, because the requester took no action.

## For operators

- **The first run may sweep a backlog.** On an instance that has been in use
  before this feature existed, every request that lapsed in the past becomes
  eligible at once. That is the intended cleanup, but the first sweep can
  transition and delete considerably more than a routine hourly run. It is
  bounded to 500 requests per run, so a large backlog is worked through over
  several hours rather than in one transaction.
- **The job is fail-soft per request.** One request that cannot be cleaned up is
  logged and skipped; the rest of the batch still completes. Check the Nextcloud
  log for `Keepiq: could not expire secret request` if you expect a request to
  have been swept and it has not been.
- **Nothing notifies the requester** that a request lapsed. They see it when they
  look at their requests. This is a deliberate omission for now, not an oversight.

## Reference

- [Audit trail](./audit-trail.md) — where `request.expired` is recorded.
- [Architecture & data model](./ARCHITECTURE.md) — the SecretRequest entity.
