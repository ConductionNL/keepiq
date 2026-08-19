## 1. Shared member editor

- [ ] 1.1 Extract the named-member editor used by `SecretRequestCreateDialog.addCustomField()` into one reusable piece (component or composable) carrying the name/value list plus its validation.
  - Refuses reserved names (`key`, `login`, `url`) with a reason, duplicates, and blanks
  - Extract the VALIDATION at minimum; the request dialog's "name a member that does not exist yet" affordance is request-only and must not be forced onto the secret dialogs
- [ ] 1.2 Point `SecretRequestCreateDialog` at the extracted piece so there is one implementation, not two.

## 2. Create

- [ ] 2.1 Add additional fields to `SecretCreateDialog`: add and remove named members before the secret exists.
- [ ] 2.2 Include the members in the payload so the store encrypts them as one blob.
  - The server must still receive only ciphertext; no member name or value in plaintext

## 3. Edit

- [ ] 3.1 Pre-fill `SecretEditDialog` from the decrypted blob so existing members are shown with their names and values.
- [ ] 3.2 Allow renaming a member, changing its value, adding another and removing one, writing the whole blob back.
  - Removing the last member yields an EMPTY blob, not null, so "none" stays distinguishable from "not loaded"

## 4. Tests

- [ ] 4.1 Vitest for the shared editor: reserved names, duplicates and blanks refused with a reason; add and remove work.
- [ ] 4.2 Vitest for create: members reach the payload, and the payload carries no plaintext member the server should not see.
- [ ] 4.3 Vitest for edit: existing members are pre-filled from the decrypted secret; rename, re-value, add and remove all round-trip; removing the last member sends an empty blob.
- [ ] 4.4 Verify each new/modified spec scenario is driven by a test or carries a reason-bearing `@e2e exclude`.

## 5. Quality

- [ ] 5.1 Translate new UI strings into all 36 locales so the parity ratchet stays green.
- [ ] 5.2 Run the full sweep — hydra gates, vitest, eslint, prettier, `openspec validate --strict` — and confirm a `@spec` anchor or reason-bearing exclude on every changed method.

## Acceptance criteria

- An owner can add additional fields when creating a secret and edit them afterwards, without involving anyone else
- A member named `key`, `login` or `url` is refused with a reason and never written to that column
- Members round-trip through encryption: what was typed is what decrypts
- Removing the last member leaves an empty blob, not null
- The reserved-name rule has ONE implementation shared with the request dialog

## Known limitation to state, not fix

Every member edit re-encrypts and rewrites the whole blob, because that is the storage unit. An edit made from a stale decrypted copy therefore drops members another session added in the meantime. Inherent to the single-blob design (which the secrets and import specs deliberately require); the edit dialog is pre-filled from the currently decrypted secret, so the window is small.
