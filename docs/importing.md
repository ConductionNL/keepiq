# Importing secrets into Keepiq

Keepiq can import an existing vault from other password managers. The whole
import runs **in your browser**: the file you pick is parsed locally, every
sensitive value is encrypted with your vault's key before anything is sent, and
the plaintext never reaches the server. You must **unlock your vault** before
importing — the encryption needs your key in the browser session.

Open the import wizard from the **Import** button in the vault toolbar.

## Supported formats

| Source | What to export | Notes |
| --- | --- | --- |
| **Generic CSV** | Any CSV with a header row | Columns are auto-detected (`name/title`, `url/uri`, `username/login`, `password/pass`, `notes`, `folder/group`) and you can remap any column before importing. |
| **Bitwarden** | *Tools → Export vault* → `.json` or `.csv` | Login items are imported with their username, password, URL, notes, and TOTP seed; folders and collections become Keepiq folders. Cards, identities, and secure notes are not imported (they appear in the rejected list). |
| **KeePass 2.x** | *File → Export → KeePass XML (2.x)* | Group nesting becomes the folder hierarchy; custom string fields become additional fields; the entry `History` is ignored. |
| **Nextcloud Passwords** | *Settings → Backup → Export* (format "Predefined JSON") | Recommended path: it preserves folders, custom fields, and notes. CSV from the Passwords app also works via the generic CSV path. |
| **Keepiq backup** | A `.doriath-backup` file | Restore an encrypted Keepiq backup with its passphrase. |

### KDBX is not supported

Keepiq does **not** read `.kdbx` files. KDBX is an encrypted binary container,
not an interchange format — supporting it would mean asking you to type your
KeePass master password into Keepiq, which is exactly the phishing pattern we
refuse to teach. Instead, in KeePass choose **File → Export → KeePass XML (2.x)**
and import the resulting XML. The wizard detects a `.kdbx` file and shows this
guidance.

## The wizard

1. **Pick** — choose the source format and the file. KDBX files are rejected
   here with guidance.
2. **Mapping preview** — see the first rows and which source field maps to which
   Keepiq field. Sensitive cells are masked; reveal them per cell. For generic
   CSV you can remap each column.
3. **Folders** — source folders are created beneath your vault, preserving their
   hierarchy. Existing folders with the same name are reused. You can also import
   everything under one new folder.
4. **Duplicates** — rows that match an existing secret (same name + URL) are
   listed. The default is **skip**; you can choose **import as copy** (the copy
   gets an "(imported)" suffix) per row or in bulk. Keepiq never overwrites an
   existing secret.
5. **Commit** — accepted rows are encrypted and uploaded in batches with a
   progress bar.
6. **Summary** — how many were imported, skipped as duplicates, rejected (with
   reasons), and how many folders were created. You can download the rejected
   rows as a CSV (generated locally, never uploaded) to fix and re-import.

## What is not imported

- **Shares, link shares, and delegations** — set these up again in Keepiq.
- **Tags and attachments** — not part of the Keepiq secret model.
- Only **secrets and folders** migrate in this version.

## Duplicate semantics

A row is a duplicate when an existing secret has the same name (case- and
whitespace-insensitive) **and** the same URL (scheme- and trailing-slash
insensitive). This uses only the plaintext list metadata, so detection never
decrypts your existing vault. Re-importing the same file therefore skips
everything by default — safe to retry.
