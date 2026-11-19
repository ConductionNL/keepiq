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

A pattern is checked on every keystroke, in your browser and on the server, so it
must stay quick to check and read the same in both. The browser and the server
refuse the same patterns:

- **More than three optional or repeated parts.** Each `?`, `*`, `+`, `{2,}` or
  `{1,3}` counts, and so does each group with a `|` in it, or the whole pattern when
  it has one outside every group. A fixed count such as `{4}` or `{2,2}` does not
  count. One part fewer is allowed when the pattern does not start with `^`, or when
  it has a `|` outside every group, since that undoes the `^`.
- **A repeated group that holds a repeat or a `|`**, at any depth, such as `(a+)+`,
  `(a|aa)+` or `(a+){10}`. A `?` after such a group is fine: `(a+)?`.
- **A long pattern**: more than 500 characters, or more than 4096 with each counted
  group written out as often as it repeats (`(?:abcd){500}` is fine,
  `(?:abcd){600}` is not), or a count above 65535.
- **Constructs the browser and the server read differently**: a lookbehind
  (`(?<=…)`, `(?<!…)`), inline flags such as `(?i)`, atomic groups `(?>…)`,
  comments `(?#…)`, possessive quantifiers such as `a++`, back-references such as
  `\1`, `\A`, `\z` and `\Z`, POSIX classes such as `[[:alpha:]]`, an empty class
  `[]` or `[^]`, a lone `{`, `}` or `]`, and outside a class any escape other than
  `\d \D \w \W \s \S \b \B \t \n \r \f`, `\xHH` and an escaped
  `^ $ \ . * + ? ( ) [ ] { } | /`.
- **A group name used twice, or longer than 32 characters**, as in
  `(?<a>x)|(?<a>y)`.
- **Nothing to repeat**: a quantifier after `^`, `$`, `\b`, a `|`, a lookahead,
  another quantifier or the start of a group.

Plain groups, `(?:…)`, named groups `(?<name>…)`, lookaheads `(?=…)` and `(?!…)`,
and lazy quantifiers such as `+?` are fine. Advanced says so when a pattern breaks
one of these rules, and a value is checked against a pattern only up to 256
characters.

A value that does not fit its field is refused when you save, with the reason. An
import keeps such a value but drops the field's kind, so it shows as plain text.

## The `@schema` entry, for applications

The kinds are stored in the same encrypted blob as the values, under one reserved
entry named `@schema`: a map from field name to its schema. A field without an
entry is plain text, so secrets saved before kinds existed read as before.

```json
{
  "Replicas": "3",
  "Service account": "{\"type\": \"service_account\"}",
  "@schema": {
    "Replicas": { "type": "integer", "minimum": 1, "maximum": 9 },
    "Service account": { "type": "object", "hidden": true }
  }
}
```

The web app writes every value as a string: a number as its digits, a yes/no as
`"true"` or `"false"`, an object as JSON text. Read a value as its text, since a
secret written by an older client can hold a JSON number. An application that reads the decrypted
additional fields sees `@schema` as an entry of its own, so **skip it when you list
the fields**.

A 0.3.14 test build stored a hidden or yes/no field as `{ "value": …, "kind": … }`
instead. Such a field reads as hidden or yes/no as well, and is stored the way above
the next time the secret is saved.

## SSH keys

An item of type **SSH Key** has its own fields instead of one value: the
private key, its passphrase, the public key and the fingerprint. **Generate key pair** creates a
new pair in your browser (Ed25519, or RSA 4096 where the browser has no
Ed25519), and **Import from file** reads a private key file in your browser and
fills in the public key derived from it. Neither sends the private key anywhere
before it is encrypted.

The private key is stored as the item's value. The public key and its SHA256
fingerprint are stored in the encrypted additional fields. The fingerprint is
always worked out from the public key when you save, so it cannot disagree with
the key, and text that is not a private key is refused with an error on the
field.

## Names you cannot use

`key`, `login` and `url` are refused, with a reason shown, and so is `@schema`,
which holds the kinds. So is the name of one of the type's own fields, such as
Port on a Database, which is stored under that name already.

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

A request can give each field a kind, so the fill page checks what is typed. The
kinds and limits of a request are stored in plain text with the request, as its
field names are: the server and its administrators can read that a field is
hidden, its length limits and its pattern, though never the value. Keep a pattern
general enough that it does not give the value away. When the request is filled,
the server keeps the request's kinds with the filled values, and your browser gives
each filled field its kind when it merges them; a kind sent along with a fill is
ignored.

## Reference

- [Architecture & data model](./ARCHITECTURE.md) — where `additional_fields` sits on the Secret.
- [Secret request expiry](./secret-request-expiry.md) — asking someone else to fill a field in.
