<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# One-time codes and passkeys on a login

A login can hold more than a user name and password. It can also hold:

- an **authenticator key**, the seed behind a site's six-digit sign-in codes, so
  Keepiq shows the current **one-time code** next to the password;
- **passkeys** for the site, brought in by an import or a provider.

Both are stored on the login itself, encrypted in your browser like the
password. The server keeps ciphertext and cannot read either.

## Adding an authenticator key

When you create or edit a **Login**, the form has an **Authenticator key
(optional)** field. Enter the key the site gave you when you switched on
two-step sign-in, in one of these forms:

- the key as text, for example `JBSW Y3DP EHPK 3PXP` (spaces and case do not
  matter);
- the `otpauth://totp/…` link behind the site's QR code;
- the QR code itself: choose **Read a QR code image** and pick a screenshot or
  photo of it (at most 10 MB). The image is read in your browser and never
  uploaded. Only a QR code that holds an `otpauth://totp/` link is accepted, so
  a QR code for a web page or a payment is never saved as a key.

Keepiq checks the key with the same code generator it uses to show codes, so a
key that saves always gives a working code.

### The key is never shown again

After saving, the key itself is not shown anywhere: not in the form, not in the
detail panel and not in version history. The form only says **Authenticator key
saved** and offers:

- **Replace**: enter a new key (for example after resetting two-step sign-in on
  the site); **Keep the saved key** cancels;
- **Remove**: clear the key from the login.

Leaving the field alone keeps the saved key unchanged.

## Using the one-time code

The detail panel of a login with a key shows the **One-time code**, the current
code with the seconds it stays valid, and a button to copy it. The code changes
every 30 seconds (or the period the key names).

## Passkeys on a login

Passkeys arrive with an import or from a provider; you do not type them in. The
detail panel lists each passkey with its site and user name. Keepiq never shows
a passkey's private key, in any view.

To remove one, choose **Delete passkey** next to it and confirm. The login's
other passkeys stay exactly as they were. Only someone who may edit the login
can delete a passkey.

## In the list

A login with a one-time code or passkeys shows small icons in the list, card and
table views, with the text **Has a one-time code** and **Has passkeys** for
screen readers. The icons only tell that the field holds a value; nothing is
decrypted to show them.

## Sharing

| Who | Code | Authenticator key | Passkeys |
| --- | --- | --- | --- |
| A colleague with a normal share | Shown and copied | Never shown | Listed, without private keys |
| A **use-only** copy | Shown and copied, so the colleague can sign in | Never shown | Listed, without private keys; no Delete |
| A view-only or read-only copy | Shown and copied | Never shown | Listed; no Delete |
| A **link share** | Not included | Not included | Not included |

A link share carries only the password, the user name and the additional
fields: putting the key or a passkey in a link would hand out the second factor
with it.

Sharing with another Nextcloud through federation works when the other side
runs a Keepiq release that stores these fields. When it does not, Keepiq refuses
to send a login that holds a key or passkeys and names the release the other
side needs, instead of sending the login without them.

The key and passkeys also travel with:

- ownership transfers;
- version history and restore;
- offline editing;
- moving to a new encryption suite.

## Authenticator and Passkey items

Older vaults may hold separate **Authenticator** and **Passkey** items. They
keep working: you can still open, edit, share and filter them. New ones can no
longer be created. Put the key or passkeys on the login they belong to instead.
A default item type of Authenticator or Passkey in your settings now opens a
Login.

## Import and export

Bitwarden, KeePass and CXF imports put a login's one-time code and passkeys on
that login, instead of creating separate items. A CXF export writes them on
the login again, so re-importing it gives the same login back. The encrypted
backup carries both fields. The CSV export writes the key in a `totp` column
and has no column for passkeys. See [Importing](importing.md) for each format.

## Not available yet

- The Android app does not show or edit these fields yet.
- Filtering on the Passkey type finds Passkey items, not logins that hold
  passkeys: the server cannot look inside the encrypted field.
