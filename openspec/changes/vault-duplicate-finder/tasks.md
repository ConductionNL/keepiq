# Tasks: find duplicate items in the vault and merge them

## 1. Detection

- [ ] 1.1 Add `src/health/duplicates.js` returning exact and likely groups from rows with host, username, value and ownership. Verify: vitest for exact, likely, no-address, passkey and authenticator exclusion, and a recipient copy never listed.
- [ ] 1.2 Decrypt the username in `loadDecryptedRows()` and call the detector from the health worker. Verify: vitest that locking the vault drops the groups.

## 2. Report and merge

- [ ] 2.1 Add a Duplicates section to `HealthReportView.vue` listing groups with host, username, folder and a shared marker. Verify: Playwright flow on the fixture vault shows one exact and one likely group.
- [ ] 2.2 Add `src/modals/DuplicateMergeModal.vue`: pick the item to keep, preview the folded additional fields, confirm with the count of people who lose access, then update the kept item and delete the others. Verify: vitest on the field folding with a clash, a Playwright flow merge the exact group, and the hydra modal-isolation gate.

## 3. Docs

- [ ] 3.1 Document the Duplicates section and the merge rules in `docs/password-health.md`. Verify: docs build.

## Acceptance criteria

- The health report lists items that hold the same credential more than once, split into exact and likely duplicates.
- A merge keeps one item with the others' additional fields folded in and removes the rest.
- Nothing about duplicates is sent to the server.
