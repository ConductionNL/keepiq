<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Clone a secret, preview an attachment, print a login or show it as a QR code

Actions on a single secret in the detail sidebar. All of them work on what your browser already decrypted; nothing new reaches the server in plaintext.

## Clone

Choose **Clone** in the secret's **…** menu. The new-secret dialog opens with the source's type, name followed by " (copy)", URL, login, value and additional fields, in the same folder. Change what you need and save.

Saving creates an ordinary new secret: a new id, encrypted fresh in your browser for your own certificate. What a clone does **not** take over, as the dialog says:

- attachments,
- shares,
- version history,
- passkeys and the one-time code (authenticator key).

Clone is not offered for a passkey item, whose credential belongs to one item only, for a use-only copy, whose value you cannot see, or while you are offline.

## Add files while creating or editing

The **New secret** and **Edit secret** dialogs have a drop zone at the bottom. Drop one or more files on it, or choose **Add file**. The files wait in the dialog and are uploaded only after **Create secret** or **Save** succeeds, so **Cancel** adds nothing. Each file is encrypted in your browser and counts against the same size limit and quota as an upload from the sidebar.

When a file is refused, for example because it is larger than the limit, the secret stays saved and the dialog stays open with the reason next to the file. Retry it, or remove it to close the dialog. The drop zone is shown only on secrets you own and not while you are offline.

## Preview an attachment

Images (PNG, JPEG, GIF, WebP), PDFs and plain-text attachments have a **Preview** button next to Download. The file is decrypted in your browser and shown in a dialog from memory: nothing is saved to your device, and the in-memory copy is released when you close the dialog. A PDF is shown in a sandboxed frame.

Other types, including SVG images (which can carry script), offer Download only. Keepiq never builds a preview on the server: under end-to-end encryption the server cannot read the file.

## Print a login

Choose **Print** in a login's **…** menu. Enter your master password again: it is checked in your browser, the same way as before a plaintext export, and is never sent to the server. If the password is wrong, nothing is rendered for printing and the dialog says so.

Once the password is checked, a sheet with the login's name, address, username and password is prepared and your browser's print dialog opens. **Print again** opens it once more. Closing the dialog removes the sheet from the page. The sheet never holds the one-time code key: a printed second factor next to the password would defeat it.

A printed sheet is outside Keepiq's control once it leaves the printer. Keep it somewhere safe and destroy it when it is no longer needed.

## Show a password as a QR code

Choose **Show as QR code** in a login's **…** menu to hand a password to a device without Keepiq, such as a guest's phone joining your Wi-Fi. After the same master password check, the dialog shows the password as a QR code. The code is generated in your browser; nothing is fetched or sent. It encodes the password only, never the one-time code key.

Anyone who can see the screen can scan the code. Close the dialog as soon as the password has been scanned.

Print and Show as QR code are offered on a login that has a password and that you may reveal. They are not offered on a use-only copy, whose value you cannot see. They work offline too, because they only use what the detail view already decrypted. A login that asks for the master password again before revealing it asks first, then shows the dialog.
