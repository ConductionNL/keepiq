# Tasks: exchange a vault with another provider through Credential Exchange files

## 1. Protocol

- [ ] 1.1 Verify `createImportRequest`, `sealForRequest` and `openEnvelope` against the published CXP test vectors and fix drift. Verify: vitest with the vectors.

## 2. Receive and send

- [x] 2.1 Add request-as-file and response-as-file to the receive path of the dialog. Verify: vitest of a full request, foreign seal and open; Playwright flow with a fixture response file. Playwright done 2026-11-17 on a throwaway NC 34 rig: tests/e2e/workflows/cxp-file-transfer.spec.ts, two fresh accounts: request file out, sealed response back (carrying neither the name nor the value), an altered response refused with nothing imported, the real one opening the import wizard with the item; passed.
- [x] 2.2 Add Send to another provider with a loaded request file, and the item filter (all, folder, selection) in `export.js`. Verify: vitest that only the chosen items are sealed.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 3.2 Set row `portability-08` to built and archive the change. Verify: parity_verify --strict.

