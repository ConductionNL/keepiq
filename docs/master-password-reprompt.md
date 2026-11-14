<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Ask for the master password again

Once your vault is unlocked, every item in it can be shown, copied and filled
until the session times out or you lock it. For most logins that is what you
want. For a few, such as a domain admin account, a payment login or a
break-glass root key, you may want Keepiq to check who is at the keyboard
first.

Tick **Ask for my master password before showing or filling this item** when
you create or edit the item. The list then shows a lock next to its name.

## What it asks for

For a flagged item, Keepiq asks for your master password again before it:

- shows the value, or a card number, CVV, PIN or BSN;
- copies the value, the login or any other field;
- opens the edit form.

Every one of these asks again. There is no "remember for a few minutes": an
item you use all day is better left unflagged.

## What it protects against, and what not

Your master password is checked on your own device, against your encrypted
private key, and is never sent to the server. That is the same check the
plaintext export uses.

The re-prompt stops someone who sits down at your unlocked screen. It does not
protect against a modified Keepiq app or a compromised browser: the item is
still decrypted with the key that is already unlocked, and a changed client
could skip the question. Lock your vault when you leave your device.

## Sharing

The flag is yours, not the item's. When you share a flagged item, the
recipient's copy starts flagged too; after that each of you decides for your
own copy.

## Browser extension

The browser extension does not ask yet before it fills a flagged item. Until
it does, a flagged item is protected in the web app only.
