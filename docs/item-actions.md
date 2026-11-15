<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Clone a secret and preview an attachment

Two actions on a single secret in the detail sidebar. Both work on what your browser already decrypted; nothing new reaches the server in plaintext.

## Clone

Choose **Clone** in the secret's **…** menu. The new-secret dialog opens with the source's type, name followed by " (copy)", URL, login, value and additional fields, in the same folder. Change what you need and save.

Saving creates an ordinary new secret: a new id, encrypted fresh in your browser for your own certificate. What a clone does **not** take over, as the dialog says:

- attachments,
- shares,
- version history,
- passkeys and the one-time code (authenticator key).

Clone is not offered for a passkey item, whose credential belongs to one item only, for a use-only copy, whose value you cannot see, or while you are offline.

## Preview an attachment

Images (PNG, JPEG, GIF, WebP), PDFs and plain-text attachments have a **Preview** button next to Download. The file is decrypted in your browser and shown in a dialog from memory: nothing is saved to your device, and the in-memory copy is released when you close the dialog. A PDF is shown in a sandboxed frame.

Other types, including SVG images (which can carry script), offer Download only. Keepiq never builds a preview on the server: under end-to-end encryption the server cannot read the file.
