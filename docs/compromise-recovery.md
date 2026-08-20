<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Compromise recovery

If your master password has been exposed, compromise recovery generates a **new
key pair** and re-encrypts your vault under it, so the leaked key can be locked
out. Because Doriath is end-to-end encrypted (ADR-003), the server cannot do any
of this for you: every decrypt and re-encrypt happens **in your browser**, and
only ciphertext ever crosses the wire.

## What it does and does not fix

Rotating your key restores **access** under a key nobody else holds. It does not
make the old values safe.

Anyone who had your master password could already read every value in the vault.
Re-encrypting those same values under a new key does not un-read them. So the
purpose of recovery is to get you back to a working, private vault **so that you
can go and change each value at its source** — every site, every service, every
API token — in an orderly fashion.

The interface says this before you start, while it runs, and after it finishes,
and every migrated secret carries a warning until you replace its value. That
warning is not decoration: it is the actual remediation list.

## How it runs

1. Your browser generates a new RSA-4096 key pair and sends the public key plus
   the AES-wrapped new private key. Your master passwords never leave the device.
2. The server signs a certificate for the new key and creates the new suite. If
   the issued certificate does not carry the key you submitted, recovery aborts
   with a distinct error and **your vault is untouched** — safe to retry.
3. The old suite stays **active** for the duration. This is deliberate: the
   migration has to read the old ciphertext, so locking the old key first would
   make the vault unreadable at exactly the moment you need it open.
4. Your vault goes **read-only** while the migration runs. Reads stay open.
5. Record by record, in your browser: decrypt with the old key, re-encrypt under
   the new one, then **decrypt the fresh copy again with the new key and compare
   it byte-for-byte** with the original. Only a record that survives that
   comparison is written. No original ciphertext is ever discarded on the
   strength of a successful encrypt alone.
6. When nothing is left under the old key, the old suite is locked, outstanding
   link shares are revoked, passkey unlock envelopes are invalidated, and the
   write lock is released.

Progress is shown inside the recovery dialog as `n of m` across every store.

## What gets carried across, and what does not

| | |
|---|---|
| Secrets | Re-encrypted |
| Version history | The current value plus the 5 most recent versions; **older versions are deleted** and the count is reported |
| Attachments | The file key is re-wrapped; the encrypted file itself is not re-uploaded |
| Your attachment access | Re-wrapped. Other recipients' access is untouched |
| Pending fill-in requests | Locked during the migration, then re-pointed to the new key |
| Link shares | **Revoked.** Their snapshots were sealed to the leaked key; re-share afterwards |
| Emergency access | **Invalidated.** The envelope is sealed to your contact's key and escrows your old private key, so you cannot re-wrap it alone — re-establish emergency access afterwards |

## If it is interrupted

Closing the tab does not lose progress. The migration stays in progress, your
vault stays readable, and the next time you open Doriath a banner tells you how
many records remain and offers to resume.

Resuming asks for your **previous** master password — the current one is already
in the unlocked session, and only the old private key can read what has not moved
yet. Only records still under the old key are processed, so resuming never
re-does work.

The migration cannot be finished early. If records remain that nobody has
attempted, the server refuses to end it and asks you to resume, because ending it
would lock out everything the migration had not yet reached.

## If a secret cannot be carried across

Occasionally a value cannot be decrypted with the old key at all — corrupted
ciphertext, or a value left behind by an earlier rotation. Retrying will not help
those, and leaving the migration open forever would leave your vault read-only
indefinitely.

So recovery offers a choice, and names the affected secrets:

- **Try these again** — re-attempt only the records that failed.
- **Finish anyway** — end the rotation and accept that those secrets can no
  longer be opened. The button states how many.

**This is the one irreversible decision in Doriath.** Finishing locks the old
key, so the listed secrets become unopenable. Their stored data is kept, so a
future recovery tool could still reach it, but nothing in the app will open them
again. You will need to set those values afresh at their source. Nothing is
locked out without you choosing it: absent that confirmation, the server refuses
to finish.

Note that only values that **already could not be read** with the old key are
ever affected. A record whose re-encryption fails verification is never treated
this way — that indicates a problem with the new key, so the run stops instead
and nothing is lost.

## While a rotation is running

- The vault is read-only. Creating or changing secrets, attachments, link shares
  and fill-in requests is refused until the migration finishes or is resumed to
  completion. Reading stays available throughout.
- Starting a **second** rotation is refused. Doing so would strand whatever the
  first had not reached under a key nothing would ask for again.
