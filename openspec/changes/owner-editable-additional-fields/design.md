## Context

`additional_fields` is a single RSA-encrypted blob on the Secret. The store encrypts it on write and decrypts it to a plain object on read, `SecretDetail` renders those members read-only, and both controller methods accept it — but neither owner-facing dialog references it, so there is no write path for the person the secret belongs to.

The only writers today are other actors: the write-for-application dialog, the share dialogs (pass-through), import, and a secret-request fill submitted by an external recipient.

## Goals / Non-Goals

**Goals:**
- An owner can add, rename, re-value and remove additional fields when creating and when editing a secret.
- Reserved names are refused rather than silently misrouted.
- One implementation of the member editor, not a third variant.

**Non-Goals:**
- Changing the storage shape. `additional_fields` stays ONE encrypted blob; per-member columns would move plaintext member names onto the Secret, which the secrets, secret-import and cxf-import-export specs explicitly place outside the plaintext boundary.
- Server-side per-member validation. The server never decrypts the blob (ADR-003), so it cannot see members; validation is client-side by necessity, and the spec already states per-member completeness is a client-side concern.
- Typed or structured members (secret vs metadata per member). Every member is ciphertext inside the blob; a per-member visibility model would be a data-model change, not a dialog change.
- Reworking `SecretDetail`'s read-only rendering, which already works.

## Decisions

### Reuse the request dialog's member editor

`SecretRequestCreateDialog.addCustomField()` already implements exactly the affordance both dialogs need: type a member name, refuse the reserved ones (`key`, `login`, `url`), refuse duplicates, refuse blanks. Writing a second copy would mean three implementations of one rule across the app, and the rule matters — a member named `key` would look like a second value field while the backend routes the real `key` to its own column.

The extraction target is a small component or composable holding the name/value list and its validation, consumed by all three dialogs. Which of the three it lives in is an implementation choice; that there is one is not.

### Reserved names are refused, not remapped

The alternative — silently routing a member called `url` to the `url` column — is worse than refusing it: the user asked for an additional field and would get a metadata change, with the plaintext/ciphertext distinction inverted for that value. Refusal with a reason keeps the two field families visibly separate.

### Removal deletes the member, not the blob

Removing the last member MUST produce an empty blob rather than a null column, so that "no additional fields" and "additional fields not yet loaded" stay distinguishable client-side. The store already tolerates both, so this is a discipline note rather than a behaviour change.

## Risks / Trade-offs

- **Whole-blob rewrite on every member edit.** Changing one member re-encrypts and rewrites all of them, because that is the storage unit. Consequence: an edit made from a stale decrypted copy silently drops members another session added. The edit dialog is pre-filled from the currently decrypted secret, so the window is small, but it exists and is inherent to the single-blob design — worth stating rather than discovering.
- **Version history grows a snapshot per member edit.** `fieldsChanged()` includes `additionalFields`, so each member change produces a version row. Correct, but it makes the history noisier for secrets with many members.
- **A member name is not searchable, and never should be.** Users may expect to search for `client-id`; they cannot, because member names live inside the ciphertext. That is the encryption boundary working as designed, and the UI should not imply otherwise.
- **Three consumers of one editor.** Extracting shared UI risks coupling the request dialog's behaviour to the secret dialogs'. They agree today on names and validation; if a future requirement makes requests differ (asking for a member that does not exist yet is already request-only), the shared piece must stay the validation, not the whole control.
