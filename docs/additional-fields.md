<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Additional fields

A secret has a few fields Keepiq knows by name — its value, a login, a URL — and
then whatever else you need. Those extras are **additional fields**: named entries
you add yourself, like `client-id`, `tenant` or `recovery-codes`.

You can add them when creating a secret and change them afterwards from **Edit**.

## They are encrypted, names included

All additional fields live together in one encrypted blob on the secret. The
encryption happens in your browser before anything is sent, so the server stores
ciphertext and cannot read it — the same guarantee as the secret's own value.

**The field names are inside that blob too.** That a secret has a field called
`recovery-codes` is itself information, so it is not left in the clear.

Two consequences follow from this, and both are deliberate:

- **You cannot search for a field name.** Searching happens on the server, which
  cannot read the names. This is the encryption boundary working, not a missing
  feature.
- **Changing one field rewrites them all.** The blob is the unit of storage, so
  saving re-encrypts every field, not just the one you touched. If you have the
  same secret open in two places and both save, the later save wins for the whole
  set — a field added in the other session is lost. The Edit dialog always loads
  the current values first, so the window is small, but it exists.

## Three names you cannot use

`key`, `login` and `url` are refused, with a reason shown.

Those names already belong to the secret's own fields. A field named `url` would
not be a second field that happens to be called that — it would collide with the
real URL, which is stored as searchable plaintext rather than ciphertext. So
instead of quietly putting your value somewhere you did not intend, Keepiq asks
you to pick a different name.

The same rule applies when renaming an existing field, and when asking somebody
else to fill one in via a secret request.

## Removing them

Removing a field deletes it from the secret. Remove the last one and the secret
simply has no additional fields any more — the section disappears from the detail
view.

Every change is recorded in the secret's version history, so a field you removed
by accident can be recovered there.

## Asking somebody else instead

You do not have to know the value yourself. A [secret
request](./secret-request-expiry.md) can ask another person to fill in one or more
named fields, and you never see what they submit — that is write-without-read. This
page is about the case where you *do* know the value and simply want to store it.

## Reference

- [Architecture & data model](./ARCHITECTURE.md) — where `additional_fields` sits on the Secret.
- [Secret request expiry](./secret-request-expiry.md) — asking someone else to fill a field in.
