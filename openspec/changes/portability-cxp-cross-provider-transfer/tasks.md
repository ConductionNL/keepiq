# Tasks: exchange a vault with another provider through Credential Exchange files

## 1. Protocol

- [ ] 1.1 Verify `createImportRequest`, `sealForRequest` and `openEnvelope` against the published CXP test vectors and fix drift. Verify: vitest with the vectors.

## 2. Receive and send

- [ ] 2.1 Add request-as-file and response-as-file to the receive path of the dialog. Verify: vitest of a full request, foreign seal and open; Playwright flow with a fixture response file.
- [ ] 2.2 Add Send to another provider with a loaded request file, and the item filter (all, folder, selection) in `export.js`. Verify: vitest that only the chosen items are sealed.

## 3. Close out

- [ ] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 3.2 Set row `portability-08` to built and archive the change. Verify: parity_verify --strict.

