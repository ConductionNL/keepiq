<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# What an application is asking people for

An approved application can ask a human to put a credential **into** the
application's own vault, using a secret request. The person filling it in needs no
Nextcloud account, and the application cannot read anything else in the vault.

Those requests used to be invisible to every person on the instance. They are
recorded as created by `application:<id>`, which no user's request listing can
match, and the Secrets they target belong to the application, so they appear in
nobody's vault. The only way to list them was the application's own API token. The
events were in the audit trail, but there was nowhere to see what was
**outstanding**.

## Where to look

Open **Applications → the application → Outstanding secret requests**. Each row
shows:

| Column | Notes |
|---|---|
| Status | Pending, Fulfilled, Declined, Expired |
| Expiry | "Expires <date>", "Expired <date>", or **"No expiry"** — a link that works forever |
| Token | Truncated. The full value goes to the clipboard only via **Copy fill link** |
| Requested fields | The field names asked for, e.g. `key, login` |

Every status is listed, not only the outstanding ones, so the question "what has
this application been asking people for" has an answer after the fact too.

**A row can read "Expired" while the database still says pending.** The sweeper
runs hourly, but a lapsed link is refused the moment it is opened, so the list
judges the expiry on its timestamp rather than on the stored status. What you see
is what a recipient would get.

## Who can see it

**Administrators only** — including for applications they did not register.

This is deliberate, and the cheaper alternative was rejected: showing the requests
to whoever registered the application. An application's vault belongs to no single
user, registering it is a historical act rather than an ongoing responsibility, and
the registrar may have left the organisation. Tying audit visibility to that would
make "who may see this" depend on who happened to click Register months ago.

What is shown is plaintext metadata — status, timestamps, and the names of the
requested fields. **No submitted value is readable from the listing**, by anyone,
including an administrator. That is the write-without-read property the whole
feature rests on and it is unaffected by who is looking.

## Revoking a request

A pending fill link is a **bearer credential in a URL**: whoever holds it can
submit against it. So it needs an off switch that does not depend on the
application cooperating, and **Revoke** is it.

Revoking asks for confirmation, and names the fields being asked for, because the
consequences reach outside Keepiq:

- the fill link stops working immediately, even if someone already has it;
- the empty placeholder Secret the request created is deleted;
- if the application was waiting on that credential, **its integration may stop
  working mid-flow**.

A Secret that already holds a value is never deleted by a revoke — a request being
withdrawn must not cost anyone a working credential.

The revoke is recorded in the audit trail against **the administrator who did it**,
not against the application, so the trail answers "who ended this link".

## Reference

- [Secret request expiry](./secret-request-expiry.md) — how expiry is set and swept.
- [Audit trail](./audit-trail.md) — where revocations are recorded.
