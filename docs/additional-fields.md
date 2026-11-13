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

## Saying what a field holds

Each field has a **kind**: Text, Multi-line text, Password, URL, Email, Number,
Yes/no, Date or JSON object. The kind decides how you enter the value and how the
detail view shows it: a URL becomes a link, a Password stays hidden until you
choose Show, and a JSON object opens in a code viewer.

Under **Advanced** you see what the kind means as a JSON Schema property, the same
words OpenRegister uses: a type (`string`, `integer`, `number`, `boolean`,
`object`), a format for text (such as `uri`, `email` or `date`), and limits such
as a minimum and maximum length, a pattern, or a minimum and maximum number. The
**Hidden** switch works on every type, so a JSON object or a URL with a token in it
can stay hidden too.

A value that does not fit its field is refused when you save, with the reason. An
import keeps such a value but drops the field's kind, so it shows as plain text.

## The `@schema` entry, for applications

The kinds are stored in the same encrypted blob as the values, under one reserved
entry named `@schema`: a map from field name to its schema. A field without an
entry is plain text, so secrets saved before kinds existed read as before.

```json
{
  "Port": "5432",
  "Service account": "{\"type\": \"service_account\"}",
  "@schema": {
    "Port": { "type": "integer", "minimum": 1, "maximum": 65535 },
    "Service account": { "type": "object", "hidden": true }
  }
}
```

Every value stays a string: a number as its digits, a yes/no as `"true"` or
`"false"`, an object as JSON text. An application that reads the decrypted
additional fields sees `@schema` as an entry of its own, so **skip it when you list
the fields**.

## Names you cannot use

`key`, `login` and `url` are refused, with a reason shown, and so is `@schema`,
which holds the kinds.

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
