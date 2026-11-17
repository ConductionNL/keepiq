---
kind: code
---

# Find duplicate items in the vault and merge them

## Why

Duplicates are caught only while importing: the import wizard compares each incoming row with the existing vault by name and address and asks whether to skip it or import it as a copy (`src/store/modules/import.js:58-61`, the `duplicates` step). Items that are already duplicated stay duplicated: two imports from two browsers, a login saved once by hand and once by the extension, or a colleague's shared copy next to one's own. Nothing finds them, and nothing merges them. The password health report already sees part of the problem, since it marks values that are reused, but it treats two copies of the same login as two logins with a reused password.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `vault-25` | Find duplicate items already in the vault and merge them. | `no`: duplicates are caught only while importing; nothing finds or merges duplicates already stored |

### Demand

- Feature request, https://community.bitwarden.com/t/duplicate-removal-tool-report-including-merge/648
- The row is in the core area (vault).

### Competitors rated yes

No competitor is rated yes on this row.

## What Changes

- **A Duplicates section in the password health report.** Run in the browser over the decrypted vault, it groups the user's own secrets into exact duplicates (same address host, same username and same value) and likely duplicates (same address host and same username, different value).
- **Merge.** For a group the user picks the item to keep. Keepiq folds the other items' additional fields into it (the kept item wins on a clash, and a clashing value is kept under a suffixed key), saves the kept item, and deletes the others. When the trash exists (`vault-trash-and-archive`), the others go to the trash and can be restored.
- **Guard rails.** Only items the user owns can be merged. A shared item in a group is marked, and merging it away says that its recipients lose access. Passkey and authenticator items are never grouped.

## Capabilities

### New Capabilities

- `vault-duplicates`: client-side detection of duplicate items in the user's own vault and a guided merge.

### Modified Capabilities

- None in delta form. `password-health` keeps its "No Server-Side Health Knowledge" requirement, which this change honours by running entirely in the browser.

## Impact

- **Backend**: none. Merge uses `PUT /api/v1/secrets/{id}` for the kept item and the existing delete route for the others.
- **Frontend**: a pure `src/health/duplicates.js`, a Duplicates section in `src/views/HealthReportView.vue`, a `src/modals/DuplicateMergeModal.vue`, and a decrypt step that includes the username.
- **Database**: none.
- **Security**: no digest, grouping or verdict reaches the server; the merge writes only fresh ciphertext through the existing update path.
- **Cross-app**: none.
