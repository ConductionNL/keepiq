<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Password health

Keepiq analyses the **hygiene** of your stored credentials — flagging weak,
reused, old, and (optionally) breached passwords — and shows an overall vault
health score with a per-finding report. Because Keepiq is always end-to-end
encrypted (ADR-003), **all of this analysis runs in your browser**, on the
unlocked vault, in memory. Nothing about the strength, reuse, or breach status
of a password is ever sent to or stored on the server.

## Why it is all client-side

The server stores only ciphertext (RSA-encrypted with your public key) and never
holds your private key. It literally **cannot** score a password, compare two
values for reuse, or hash a value for a breach lookup — the plaintext exists only
in your unlocked browser. So the health engine runs there, in a dedicated web
worker, and discards every derived signal (scores, digests, findings) the moment
the vault locks. A persisted strength score would itself be a crackability
oracle ("attack the weak ones first"), so nothing health-related is written to
`localStorage`, `sessionStorage`, IndexedDB, or the server.

## What is analysed

- **Strength** — each *password-bearing* value is scored with zxcvbn (0–4) and
  shown as a colour-coded badge. Machine key material (PEM private keys, long
  base64/hex API tokens, values over 72 characters) is **excluded** from
  strength scoring — zxcvbn on a random token is noise — but still participates
  in reuse detection.
- **Reuse** — secrets whose decrypted values are byte-identical are flagged. The
  worker compares in-memory SHA-256 fingerprints; the fingerprint map is
  discarded with the rest of the health state. Only exact matches are flagged
  ("identical passwords") — near-duplicate detection is out of scope for now.
- **Age** — a server-maintained `key_updated_at` field records when each secret's
  encrypted `key` blob last changed. Renaming a secret or moving it between
  folders does **not** reset it; only changing the value does. Secrets older than
  your staleness threshold (90 / 180 / 365 days, or never; default 365) are
  flagged stale.
  - Caveat: an encryption-suite **compromise-recovery migration** re-encrypts the
    blob and so does reset `key_updated_at`. This is a rare, documented
    false-reset — the value did not change but its ciphertext did.
- **Possibly compromised** — secrets carrying the `possibly_compromised_at` flag
  from a suite compromise recovery are listed with a rotate-this-value call to
  action. This is the one finding the server already knows about.
- **Unencrypted address** — logins saved with a plain `http://` address, so
  the password crosses the network in the clear. Addresses on `localhost`,
  loopback and private-network hosts (`10.x`, `172.16.x` to `172.31.x`,
  `192.168.x`, link-local, and the IPv6 equivalents) are not listed. **Change to
  https** next to a login saves the same address with `https://` and analyses the
  vault again; only the scheme changes, so check that the site answers on https.
  The check reads the login's plain-text address in the browser and does not
  lower the health score.
- **Two-factor available** and **Passkey available** — optional; see *Site
  directory* below.
- **Breached** — optional; see below.

## Duplicates

The report also lists items your vault holds more than once, found in the
same browser pass as the other findings:

- **Exact duplicates** have the same address host, the same username and the
  same password. Saving one login from two browsers, or once by hand and once
  from the extension, gives you one of these.
- **Likely duplicates** have the same host and username but a different
  password. Two genuinely separate accounts can look like this (a personal and a
  work login on one site with one email address), so check before you merge.
- Items without an address are only grouped when the name, username and
  password are all the same.

The host is read from the address, so `https://www.github.com/login` and
`github.com` count as one host. Usernames are compared without case or
surrounding spaces. Passkeys and authenticator (one-time code) items are never
grouped, and neither are copies other people shared with you: only items you
own are listed. When Keepiq cannot tell your own items from shared copies, the
Duplicates section is left out rather than shown incomplete.

### Merging

**Merge** on a group opens a dialog where you pick the item to keep. For an
exact group the first item is picked for you; for a likely group you always
choose.

- The kept item keeps its own address, username, password and additional
  fields. The others' additional fields are added to it. When a field name is
  already taken with a different value, that value is kept under the name with
  a number, such as `pin (2)`; a value the field already holds is not repeated.
  The dialog lists the field names the kept item will have, never their values.
- The other items then move to the trash, where you can restore them one by
  one. Their attachments and version history go with them.
- Before you confirm, the dialog says how many people lose access because an
  item being removed is shared with them.

The kept item is re-encrypted in your browser and saved through the normal
update; the others are deleted through the normal delete. The server sees those
writes and nothing else: no group, count or verdict about duplicates is sent to
it.

## Breach checking (opt-in, k-anonymity)

When enabled, Keepiq checks password-bearing values against the
[Have I Been Pwned](https://haveibeenpwned.com/) corpus using the **k-anonymity
range protocol**:

1. Your browser computes `SHA-1(value)` and keeps the 35-character suffix local.
2. It sends **only the first 5 hash characters** to a Keepiq server proxy.
3. The proxy forwards that 5-char prefix to the HIBP range API (with response
   padding) and returns the suffix list verbatim.
4. Your browser does the suffix match locally.

The full hash and the password **never leave your browser**. The 5-char prefix
matches roughly 800 known-breached passwords plus the entire space of all
others, so it cannot identify your password. The instance proxy is the only
party HIBP ever sees (your IP and query timing are never exposed to a third
party), and it never logs a prefix together with a user id.

Breach checking is **double-gated** and **off by default**:

- an instance-wide admin setting (**Breach checking → Enable for this
  instance**), and
- a per-user opt-in on the Password health report.

Both must be on for any breach UI to appear or any breach traffic to occur —
municipal and air-gapped instances make no surprise external calls. If the HIBP
API is unreachable, the breach category shows *unavailable* and the other
findings are unaffected.

## Site directory (opt-in)

With the site directory switched on, the report adds two categories:

- **Two-factor available** — logins for sites that offer one-time codes, where
  your vault holds no code for the login: neither on the login itself nor as an
  authenticator item for the same site.
- **Passkey available** — logins for sites that offer passkeys, where your vault
  holds no passkey for the site: neither on the login nor as a passkey item
  whose relying party matches the login's domain.

A site matches by the login's host first, then by its registrable domain, so a
login for `login.example.com` matches a directory entry for `example.com`. The
button next to a finding opens the directory's own page for that site, so you
can check the entry before you act on it. Neither category lowers the health
score: they are suggestions.

The data comes from two public, community-run directories by 2factorauth:

| Category | Source | Licence |
| --- | --- | --- |
| Two-factor available | [2FA Directory](https://2fa.directory/), `api.2fa.directory/v4/totp.json` | MIT |
| Passkey available | [Passkeys Directory](https://passkeys.2fa.directory/), `passkeys-api.2fa.directory/v1/supported.json` | CC BY 4.0 |

**How it stays private.** Asking a directory about one site at a time would tell
its operator which sites you have. Keepiq never does that. Once a day a
background job on the server downloads **both lists whole**, keeps only each
domain and its documentation link, and stores the copy in Keepiq's app data.
Your browser fetches that copy from `GET /api/v1/site-directory` — the same
file for every user, cached with an ETag — and does the matching itself. The
request carries nothing about your vault, and no login, address, flag or count
is sent back. If a daily download fails, the last good copy is kept.

**Switching it on.** The setting is **off by default**, so an instance makes no
external call until an administrator decides it may: Keepiq admin settings →
General → **Site directory** → *Use the site directory in the password health
report*. The first copy arrives with the next run of the daily job. While the
setting is off, or before the first copy, `GET /api/v1/site-directory` answers
404 and the report says the two categories are unavailable; every other
category works as before.

## What the server knows

Nothing about password health, and nothing about duplicates. The only health-relevant facts derivable from the
database are `key_updated_at` (ciphertext age — a fact the server already knows
from its own write log) and the pre-existing `possibly_compromised_at` flag. No
endpoint accepts a score, digest, reuse datum, full hash, or breach verdict from
the client. The breach proxy accepts a 5-char prefix and nothing else, and the
site directory endpoint takes no input at all: it serves the same public list to
every user. A database dump reveals nothing about the strength,
reuse, or breach status of any secret value.
